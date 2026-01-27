<?php

namespace KillStatsAPI;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\utils\Config;
use pocketmine\Player;

class Main extends PluginBase implements Listener {

    /** @var Config */
    private $kills;

    /** @var array playerLowerName => remainingTicks (20 ticks = 1 second) */
    private $pvpTicks = [];

    /** @var array playerLowerName => opponentName (string, case preserved when possible) */
    private $pvpOpponent = [];

    /** @var array playerLowerName => array of float timestamps (microtime(true)) to compute CPS */
    private $clickTimestamps = [];

    /** @var array victimLowerName => last damage event hash (to detect new hits via getLastDamageCause) */
    private $lastDamageHash = [];

    /** @var int reward on kill */
    private $killReward = 200;

    public function onEnable() : void {
        @mkdir($this->getDataFolder());

        // YML top kills
        $this->kills = new Config($this->getDataFolder() . "kills.yml", Config::YAML, []);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // Scheduler: run every tick (1 tick = 1/20s) to detect hits via getLastDamageCause and update PvP timers/popups
        $this->getServer()->getScheduler()->scheduleRepeatingTask(
            new \pocketmine\scheduler\CallbackTask([$this, "onTick"]),
            1
        );

        $this->getLogger()->info("§aKillStatsAPI cargado correctamente.");
    }

    /**
     * Ejecutado cada tick (20 TPS). 
     * - Detecta nuevos daños leyendo getLastDamageCause() de cada jugador (solo uso de getLastDamageCause/getDamager tal como pediste).
     * - Activa PvP 15s (15*20 ticks) para atacante y víctima cuando detecta un nuevo golpe.
     * - Lleva CPS por atacante (basado en timestamps por cada golpe detectado).
     * - Envía sendPopup a 20 TPS mientras esté en PvP y notifica salida cuando termina.
     */
    public function onTick(): void {
        // 1) Detectar nuevos golpes revisando getLastDamageCause de cada jugador
        foreach($this->getServer()->getOnlinePlayers() as $victim) {
            if(!($victim instanceof Player)) continue;
            $victimName = $victim->getName();
            $victimKey = strtolower($victimName);

            try {
                $last = $victim->getLastDamageCause();
            } catch(\Throwable $e) {
                $last = null;
            }

            if($last !== null && method_exists($last, "getDamager")) {
                $damager = null;
                try {
                    $damager = $last->getDamager();
                } catch(\Throwable $ex) {
                    $damager = null;
                }

                if($damager instanceof Player) {
                    $attackerName = $damager->getName();
                    $attackerKey = strtolower($attackerName);

                    // compute a hash for the damage event to detect "new" damage
                    $hash = is_object($last) ? spl_object_hash($last) : md5(serialize($last));

                    if(!isset($this->lastDamageHash[$victimKey]) || $this->lastDamageHash[$victimKey] !== $hash) {
                        // nuevo golpe detectado -> activar PvP 15s para ambos
                        $ticks = 15 * 20;
                        $this->pvpTicks[$attackerKey] = $ticks;
                        $this->pvpTicks[$victimKey] = $ticks;

                        $this->pvpOpponent[$attackerKey] = $victimName;
                        $this->pvpOpponent[$victimKey] = $attackerName;

                        // registrar timestamp para CPS del atacante
                        $now = microtime(true);
                        if(!isset($this->clickTimestamps[$attackerKey])) $this->clickTimestamps[$attackerKey] = [];
                        $this->clickTimestamps[$attackerKey][] = $now;
                        // limpiar >1s
                        $oneSecAgo = $now - 1.0;
                        $this->clickTimestamps[$attackerKey] = array_filter($this->clickTimestamps[$attackerKey], function($ts) use ($oneSecAgo) {
                            return ($ts >= $oneSecAgo);
                        });
                    }

                    // actualizar hash para la víctima
                    $this->lastDamageHash[$victimKey] = $hash;
                }
            }
        }

        // 2) Actualizar timers PvP, enviar popups y notificar salida
        foreach(array_keys($this->pvpTicks) as $key) {
            if(!isset($this->pvpTicks[$key])) continue;

            $this->pvpTicks[$key]--;

            // buscar jugador online por nombre (case-insensitive)
            $player = $this->getServer()->getPlayerExact($key); // try exact (unlikely, since key is lowercase)
            if(!$player instanceof Player) {
                foreach($this->getServer()->getOnlinePlayers() as $pl) {
                    if(strtolower($pl->getName()) === $key) {
                        $player = $pl;
                        break;
                    }
                }
            }

            $remainingTicks = max(0, $this->pvpTicks[$key]);
            $remainingSeconds = (int) ceil($remainingTicks / 20);

            if($player instanceof Player) {
                // CPS
                $cps = 0;
                if(isset($this->clickTimestamps[$key])) {
                    $now = microtime(true);
                    $threshold = $now - 1.0;
                    $this->clickTimestamps[$key] = array_filter($this->clickTimestamps[$key], function($ts) use ($threshold) {
                        return $ts >= $threshold;
                    });
                    $cps = count($this->clickTimestamps[$key]);
                }

                // TU HP
                $yourHp = (string) round($player->getHealth(), 1);

                // SU HP
                $opHp = "-";
                if(isset($this->pvpOpponent[$key])) {
                    $oppName = $this->pvpOpponent[$key];
                    $op = $this->getServer()->getPlayerExact($oppName);
                    if($op === null) {
                        foreach($this->getServer()->getOnlinePlayers() as $pl) {
                            if(strtolower($pl->getName()) === strtolower($oppName)){
                                $op = $pl;
                                break;
                            }
                        }
                    }
                    if($op instanceof Player) {
                        $opHp = (string) round($op->getHealth(), 1);
                    }
                }

                // enviar popup 20 TPS
                $popup = "§cPvP §r{$remainingSeconds} restantes\n§eCPS: §r{$cps} §7| §eTU HP: §r {$yourHp} §7| §eSU HP: §r{$opHp}";
                try{
                    $player->sendPopup($popup);
                }catch(\Throwable $e){
                    // ignorar si no soporta
                }
            }

            if($this->pvpTicks[$key] <= 0) {
                // terminó PvP para este jugador
                unset($this->pvpTicks[$key]);

                // notificar salida si está online
                if(isset($player) && $player instanceof Player){
                    try{
                        $player->sendPopup("§aSaliste del §cModo PvP");
                    }catch(\Throwable $e){}
                }

                // limpiar mappings
                unset($this->pvpOpponent[$key], $this->clickTimestamps[$key]);
            }
        }
    }

    /**
     * Evento para sumar kills automaticamente, dar recompensa y mostrar title
     * Usa getLastDamageCause()->getDamager() tal como pediste.
     */
    public function onDeath(PlayerDeathEvent $event) {
        $victim = $event->getPlayer();

        $killer = null;
        try {
            $last = $victim->getLastDamageCause();
            if($last !== null && method_exists($last, "getDamager")) {
                $killer = $last->getDamager();
            }
        } catch(\Throwable $e){
            $killer = null;
        }

        if($killer !== null && $killer !== $victim && $killer instanceof Player) {
            $name = strtolower($killer->getName());
            $this->addKill($name);

            // dar recompensa con FrostEconomy (intento por API y fallback a money.yml)
            $this->giveEconomyReward($killer, $this->killReward);

            // enviar title al killer
            try {
                $killer->sendTitle("§b", "§cMataste a: §r" . $victim->getName());
            } catch(\Throwable $e){
                // ignore if not supported
            }
        }
    }

    /**
     * Cancela ejecución de comandos si jugador está en PvP (tiene ticks > 0).
     * Permite enviar mensajes (chat) normalmente.
     */
    public function onPlayerCommandPreprocess(PlayerCommandPreprocessEvent $event) : void {
        $player = $event->getPlayer();
        $key = strtolower($player->getName());
        if(isset($this->pvpTicks[$key]) && $this->pvpTicks[$key] > 0){
            $event->setCancelled(true);
            $player->sendMessage("§cNo puedes usar comandos durante PvP.");
        }
    }

    /**
     * Limpiar datos si el jugador sale del servidor
     */
    public function onPlayerQuit(PlayerQuitEvent $event) : void {
        $name = strtolower($event->getPlayer()->getName());
        unset($this->pvpTicks[$name], $this->pvpOpponent[$name], $this->clickTimestamps[$name], $this->lastDamageHash[$name]);
    }

    /**
     * API – obtener kills
     */
    public function getKills(string $player) : int {
        $player = strtolower($player);
        return $this->kills->get($player, 0);
    }

    /**
     * API – sumar kill a un jugador
     */
    public function addKill(string $player) : void {
        $player = strtolower($player);
        $kills = $this->kills->get($player, 0) + 1;

        $this->kills->set($player, $kills);
        $this->kills->save();
    }

    /**
     * API – obtener top global
     * @return array [player => kills]
     */
    public function getTop(int $limit = 10) : array {
        $all = $this->kills->getAll();

        arsort($all); // ordena tops kill

        return array_slice($all, 0, $limit, true);
    }

    /**
     * Intenta otorgar la recompensa usando FrostEconomy API si está presente.
     * Si no hay método disponible, modifica directamente money.yml dentro del datafolder del plugin FrostEconomy (fallback).
     */
    private function giveEconomyReward(Player $player, $amount) : void {
        $eco = $this->getServer()->getPluginManager()->getPlugin("FrostEconomy");
        $name = $player->getName();

        if($eco !== null){
            // intentar varios nombres de método comunes
            $methods = [
                "addMoney", "giveMoney", "deposit", "addBalance", "add", "addFunds", "addToBalance", "give",
            ];
            foreach($methods as $m){
                try{
                    if(method_exists($eco, $m)){
                        // intentar con Player
                        try{
                            $eco->{$m}($player, $amount);
                            return;
                        }catch(\Throwable $e){
                            // intentar con nombre
                        }
                        try{
                            $eco->{$m}($name, $amount);
                            return;
                        }catch(\Throwable $e){
                            // ignora y sigue probando otros métodos
                        }
                    }
                }catch(\Throwable $e){}
            }
        }

        // Fallback: editar money.yml dentro de FrostEconomy data folder si existe
        try{
            if($eco !== null && method_exists($eco, "getDataFolder")){
                $efolder = $eco->getDataFolder();
                $mfile = $efolder . "money.yml";
                if(file_exists($mfile)){
                    $mcfg = new Config($mfile, Config::YAML);
                    // probar claves comunes
                    $keys = [$name, strtolower($name), strtoupper($name)];
                    $foundKey = null;
                    foreach($keys as $k){
                        if($mcfg->exists($k)){
                            $foundKey = $k;
                            break;
                        }
                    }
                    if($foundKey === null){
                        // si no existe, usar exact name
                        $foundKey = $name;
                    }

                    $val = $mcfg->get($foundKey, 0);
                    if(is_array($val)){
                        if(isset($val["money"])) $current = $val["money"];
                        elseif(isset($val["balance"])) $current = $val["balance"];
                        else $current = 0;
                    } else {
                        $current = is_numeric($val) ? $val : 0;
                    }

                    $new = (float)$current + (float)$amount;
                    $mcfg->set($foundKey, $new);
                    $mcfg->save();
                    return;
                }
            }
        }catch(\Throwable $e){
            // si todo falla no hay nada que podamos hacer 😔, pero arreglarlo si xD
        }
    }
}