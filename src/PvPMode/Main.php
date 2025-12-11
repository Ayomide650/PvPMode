<?php

declare(strict_types=1);

namespace PvPMode;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\utils\Config;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\world\Position;
use pocketmine\scheduler\Task;

class Main extends PluginBase implements Listener {

    private Config $arenas;
    private array $duels = [];
    private array $requests = [];
    private array $kit = [];
    private ?Position $spawn1 = null;
    private ?Position $spawn2 = null;
    private array $stats = [];

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        
        @mkdir($this->getDataFolder());
        $this->arenas = new Config($this->getDataFolder() . "arenas.yml", Config::YAML);
        
        $this->loadArenas();
        
        $this->getLogger()->info(TF::GREEN . "PvPMode by Firekid846 enabled!");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        
        if ($command->getName() !== "pvp") {
            return false;
        }

        if (count($args) < 1) {
            $this->sendHelp($sender);
            return true;
        }

        $action = strtolower($args[0]);

        switch ($action) {
            case "ask":
            case "invite":
                return $this->sendRequest($sender, $args);
            case "accept":
                return $this->acceptRequest($sender);
            case "reject":
            case "deny":
                return $this->rejectRequest($sender);
            case "kit":
                return $this->manageKit($sender, $args);
            case "set":
                return $this->setSpawn($sender, $args);
            case "forfeit":
            case "leave":
                return $this->forfeitDuel($sender);
            case "stats":
                return $this->showStats($sender, $args);
            case "top":
                return $this->showTop($sender);
            default:
                $this->sendHelp($sender);
                return true;
        }
    }

    private function sendRequest(CommandSender $sender, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game!");
            return true;
        }

        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /pvp ask <player>");
            return true;
        }

        $playerName = $sender->getName();

        if ($this->isInDuel($playerName)) {
            $sender->sendMessage(TF::RED . "You're already in a duel!");
            return true;
        }

        $targetName = $args[1];
        $target = $this->getServer()->getPlayerByPrefix($targetName);

        if ($target === null) {
            $sender->sendMessage(TF::RED . "Player not found!");
            return true;
        }

        if ($target->getName() === $playerName) {
            $sender->sendMessage(TF::RED . "You can't duel yourself!");
            return true;
        }

        if ($this->isInDuel($target->getName())) {
            $sender->sendMessage(TF::RED . "That player is already in a duel!");
            return true;
        }

        if (isset($this->requests[$target->getName()])) {
            $sender->sendMessage(TF::RED . "That player already has a pending request!");
            return true;
        }

        $this->requests[$target->getName()] = [
            "from" => $playerName,
            "time" => time()
        ];

        $sender->sendMessage(TF::GREEN . "✓ Duel request sent to " . $target->getName());

        $target->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $target->sendMessage(TF::YELLOW . $playerName . " challenged you to a duel!");
        $target->sendMessage(TF::GREEN . "/pvp accept" . TF::GRAY . " - Accept");
        $target->sendMessage(TF::RED . "/pvp reject" . TF::GRAY . " - Reject");
        $target->sendMessage(TF::GRAY . "Request expires in 60 seconds");
        $target->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        $this->getScheduler()->scheduleDelayedTask(new RequestExpireTask($this, $target->getName()), 20 * 60);

        return true;
    }

    private function acceptRequest(CommandSender $sender): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game!");
            return true;
        }

        $playerName = $sender->getName();

        if (!isset($this->requests[$playerName])) {
            $sender->sendMessage(TF::RED . "You don't have any pending duel requests!");
            return true;
        }

        $requesterName = $this->requests[$playerName]["from"];
        unset($this->requests[$playerName]);

        $requester = $this->getServer()->getPlayerExact($requesterName);

        if ($requester === null) {
            $sender->sendMessage(TF::RED . "That player is no longer online!");
            return true;
        }

        if ($this->spawn1 === null || $this->spawn2 === null) {
            $sender->sendMessage(TF::RED . "Arena spawns not set! Contact an admin.");
            $requester->sendMessage(TF::RED . "Arena spawns not set!");
            return true;
        }

        $this->startDuel($requester, $sender);

        return true;
    }

    private function rejectRequest(CommandSender $sender): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game!");
            return true;
        }

        $playerName = $sender->getName();

        if (!isset($this->requests[$playerName])) {
            $sender->sendMessage(TF::RED . "You don't have any pending duel requests!");
            return true;
        }

        $requesterName = $this->requests[$playerName]["from"];
        unset($this->requests[$playerName]);

        $sender->sendMessage(TF::YELLOW . "Duel request rejected.");

        $requester = $this->getServer()->getPlayerExact($requesterName);
        if ($requester !== null) {
            $requester->sendMessage(TF::RED . $playerName . " rejected your duel request.");
        }

        return true;
    }

    private function manageKit(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("pvpmode.kit")) {
            $sender->sendMessage(TF::RED . "You don't have permission!");
            return true;
        }

        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game!");
            return true;
        }

        $this->kit = [];
        
        foreach ($sender->getInventory()->getContents() as $slot => $item) {
            $this->kit["inventory"][$slot] = $item;
        }

        $armorInv = $sender->getArmorInventory();
        $this->kit["armor"] = [
            "helmet" => $armorInv->getHelmet(),
            "chestplate" => $armorInv->getChestplate(),
            "leggings" => $armorInv->getLeggings(),
            "boots" => $armorInv->getBoots()
        ];

        $this->saveKit();

        $sender->sendMessage(TF::GREEN . "✓ PvP kit saved!");
        $sender->sendMessage(TF::GRAY . "Players will receive this kit when dueling");

        return true;
    }

    private function setSpawn(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("pvpmode.admin")) {
            $sender->sendMessage(TF::RED . "You don't have permission!");
            return true;
        }

        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game!");
            return true;
        }

        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /pvp set <player1|player2>");
            return true;
        }

        $spawnType = strtolower($args[1]);

        if (!in_array($spawnType, ["player1", "player2"])) {
            $sender->sendMessage(TF::RED . "Invalid spawn type! Use player1 or player2");
            return true;
        }

        $pos = $sender->getPosition();

        if ($spawnType === "player1") {
            $this->spawn1 = $pos;
            $sender->sendMessage(TF::GREEN . "✓ Player 1 spawn set!");
        } else {
            $this->spawn2 = $pos;
            $sender->sendMessage(TF::GREEN . "✓ Player 2 spawn set!");
        }

        $this->saveArenas();

        return true;
    }

    private function forfeitDuel(CommandSender $sender): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game!");
            return true;
        }

        $playerName = $sender->getName();

        if (!$this->isInDuel($playerName)) {
            $sender->sendMessage(TF::RED . "You're not in a duel!");
            return true;
        }

        $duelId = $this->getDuelId($playerName);
        $duel = $this->duels[$duelId];

        $opponent = $duel["player1"] === $playerName ? $duel["player2"] : $duel["player1"];
        $opponentPlayer = $this->getServer()->getPlayerExact($opponent);

        $this->endDuel($duelId, $opponent, $playerName);

        $sender->sendMessage(TF::RED . "You forfeited the duel!");
        
        if ($opponentPlayer !== null) {
            $opponentPlayer->sendMessage(TF::GREEN . "✓ " . $playerName . " forfeited! You win!");
        }

        return true;
    }

    private function showStats(CommandSender $sender, array $args): bool {
        if (!$sender instanceof Player && count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /pvp stats [player]");
            return true;
        }

        $targetName = count($args) > 1 ? $args[1] : ($sender instanceof Player ? $sender->getName() : "");

        if (!isset($this->stats[$targetName])) {
            $this->stats[$targetName] = ["wins" => 0, "losses" => 0, "streak" => 0];
        }

        $stats = $this->stats[$targetName];
        $wins = isset($stats["wins"]) ? (int)$stats["wins"] : 0;
        $losses = isset($stats["losses"]) ? (int)$stats["losses"] : 0;
        $total = $wins + $losses;
        $winrate = $total > 0 ? round(($wins / $total) * 100, 1) : 0;

        $sender->sendMessage(TF::GOLD . "━━━━━━━ " . $targetName . "'s Stats ━━━━━━━");
        $sender->sendMessage(TF::YELLOW . "Wins: " . TF::GREEN . $wins);
        $sender->sendMessage(TF::YELLOW . "Losses: " . TF::RED . $losses);
        $sender->sendMessage(TF::YELLOW . "Win Rate: " . TF::AQUA . $winrate . "%");
        $sender->sendMessage(TF::YELLOW . "Win Streak: " . TF::GOLD . (isset($stats["streak"]) ? $stats["streak"] : 0));
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    private function showTop(CommandSender $sender): bool {
        $sorted = $this->stats;
        uasort($sorted, function($a, $b) {
            return $b["wins"] - $a["wins"];
        });

        $top10 = array_slice($sorted, 0, 10, true);

        $sender->sendMessage(TF::GOLD . "━━━━━━━ Top 10 PvP Players ━━━━━━━");
        $i = 1;
        foreach ($top10 as $player => $stats) {
            $medal = match($i) {
                1 => TF::GOLD . "🥇",
                2 => TF::GRAY . "🥈",
                3 => TF::YELLOW . "🥉",
                default => TF::WHITE . "#$i"
            };
            $sender->sendMessage($medal . TF::AQUA . " $player " . TF::GRAY . "(" . $stats["wins"] . " wins)");
            $i++;
        }
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    private function startDuel(Player $player1, Player $player2): void {
        $duelId = uniqid();
        
        $this->duels[$duelId] = [
            "player1" => $player1->getName(),
            "player2" => $player2->getName(),
            "started" => time()
        ];

        $random = mt_rand(0, 1);
        $p1Pos = $random === 0 ? $this->spawn1 : $this->spawn2;
        $p2Pos = $random === 0 ? $this->spawn2 : $this->spawn1;

        $player1->teleport($p1Pos);
        $player2->teleport($p2Pos);

        $this->giveKit($player1);
        $this->giveKit($player2);

        $player1->setHealth($player1->getMaxHealth());
        $player2->setHealth($player2->getMaxHealth());

        $player1->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $player1->sendMessage(TF::GREEN . "Duel starting in 5 seconds!");
        $player1->sendMessage(TF::YELLOW . "Opponent: " . $player2->getName());
        $player1->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        $player2->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $player2->sendMessage(TF::GREEN . "Duel starting in 5 seconds!");
        $player2->sendMessage(TF::YELLOW . "Opponent: " . $player1->getName());
        $player2->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        $this->getScheduler()->scheduleDelayedTask(new DuelStartTask($this, $player1, $player2), 20 * 5);
    }

    private function giveKit(Player $player): void {
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();

        if (empty($this->kit)) {
            return;
        }

        if (isset($this->kit["inventory"])) {
            foreach ($this->kit["inventory"] as $slot => $item) {
                $player->getInventory()->setItem($slot, $item);
            }
        }

        if (isset($this->kit["armor"])) {
            $armorInv = $player->getArmorInventory();
            $armorInv->setHelmet($this->kit["armor"]["helmet"]);
            $armorInv->setChestplate($this->kit["armor"]["chestplate"]);
            $armorInv->setLeggings($this->kit["armor"]["leggings"]);
            $armorInv->setBoots($this->kit["armor"]["boots"]);
        }
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();

        if (!$this->isInDuel($playerName)) {
            return;
        }

        $event->setDrops([]);

        $duelId = $this->getDuelId($playerName);
        $duel = $this->duels[$duelId];

        $winner = $duel["player1"] === $playerName ? $duel["player2"] : $duel["player1"];
        
        $this->endDuel($duelId, $winner, $playerName);
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();

        if ($this->isInDuel($playerName)) {
            $duelId = $this->getDuelId($playerName);
            $duel = $this->duels[$duelId];
            $opponent = $duel["player1"] === $playerName ? $duel["player2"] : $duel["player1"];
            
            $this->endDuel($duelId, $opponent, $playerName);
        }

        if (isset($this->requests[$playerName])) {
            unset($this->requests[$playerName]);
        }
    }

    private function endDuel(string $duelId, string $winner, string $loser): void {
        unset($this->duels[$duelId]);

        if (!isset($this->stats[$winner])) {
            $this->stats[$winner] = ["wins" => 0, "losses" => 0, "streak" => 0];
        }
        if (!isset($this->stats[$loser])) {
            $this->stats[$loser] = ["wins" => 0, "losses" => 0, "streak" => 0];
        }

        $this->stats[$winner]["wins"]++;
        $this->stats[$winner]["streak"]++;

        $this->stats[$loser]["losses"]++;
        $this->stats[$loser]["streak"] = 0;

        $winnerPlayer = $this->getServer()->getPlayerExact($winner);
        $loserPlayer = $this->getServer()->getPlayerExact($loser);

        if ($winnerPlayer !== null) {
            $winnerPlayer->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $winnerPlayer->sendMessage(TF::GREEN . "✓ VICTORY!");
            $winnerPlayer->sendMessage(TF::YELLOW . "Wins: " . $this->stats[$winner]["wins"]);
            $winnerPlayer->sendMessage(TF::YELLOW . "Streak: " . $this->stats[$winner]["streak"]);
            $winnerPlayer->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        }

        if ($loserPlayer !== null) {
            $loserPlayer->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $loserPlayer->sendMessage(TF::RED . "✗ DEFEAT!");
            $loserPlayer->sendMessage(TF::YELLOW . "Better luck next time!");
            $loserPlayer->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        }
    }

    public function expireRequest(string $playerName): void {
        if (isset($this->requests[$playerName])) {
            $player = $this->getServer()->getPlayerExact($playerName);
            if ($player !== null) {
                $player->sendMessage(TF::GRAY . "Duel request expired.");
            }
            unset($this->requests[$playerName]);
        }
    }

    private function isInDuel(string $playerName): bool {
        foreach ($this->duels as $duel) {
            if ($duel["player1"] === $playerName || $duel["player2"] === $playerName) {
                return true;
            }
        }
        return false;
    }

    private function getDuelId(string $playerName): ?string {
        foreach ($this->duels as $id => $duel) {
            if ($duel["player1"] === $playerName || $duel["player2"] === $playerName) {
                return $id;
            }
        }
        return null;
    }

    private function loadArenas(): void {
        $data = $this->arenas->getAll();
        
        if (isset($data["spawn1"])) {
            $s1 = $data["spawn1"];
            $world = $this->getServer()->getWorldManager()->getWorldByName($s1["world"]);
            if ($world !== null) {
                $this->spawn1 = new Position($s1["x"], $s1["y"], $s1["z"], $world);
            }
        }

        if (isset($data["spawn2"])) {
            $s2 = $data["spawn2"];
            $world = $this->getServer()->getWorldManager()->getWorldByName($s2["world"]);
            if ($world !== null) {
                $this->spawn2 = new Position($s2["x"], $s2["y"], $s2["z"], $world);
            }
        }

        if (isset($data["kit"])) {
            $this->kit = $data["kit"];
        }
    }

    private function saveArenas(): void {
        $data = [];

        if ($this->spawn1 !== null) {
            $data["spawn1"] = [
                "world" => $this->spawn1->getWorld()->getFolderName(),
                "x" => $this->spawn1->x,
                "y" => $this->spawn1->y,
                "z" => $this->spawn1->z
            ];
        }

        if ($this->spawn2 !== null) {
            $data["spawn2"] = [
                "world" => $this->spawn2->getWorld()->getFolderName(),
                "x" => $this->spawn2->x,
                "y" => $this->spawn2->y,
                "z" => $this->spawn2->z
            ];
        }

        $this->arenas->setAll($data);
        $this->arenas->save();
    }

    private function saveKit(): void {
        $data = $this->arenas->getAll();
        $data["kit"] = $this->kit;
        $this->arenas->setAll($data);
        $this->arenas->save();
    }

    private function sendHelp(CommandSender $sender): void {
        $sender->sendMessage(TF::GOLD . "━━━━━━━ PvP Duel Commands ━━━━━━━");
        $sender->sendMessage(TF::YELLOW . "/pvp ask <player>" . TF::GRAY . " - Challenge player");
        $sender->sendMessage(TF::YELLOW . "/pvp accept" . TF::GRAY . " - Accept duel");
        $sender->sendMessage(TF::YELLOW . "/pvp reject" . TF::GRAY . " - Reject duel");
        $sender->sendMessage(TF::YELLOW . "/pvp forfeit" . TF::GRAY . " - Give up");
        $sender->sendMessage(TF::YELLOW . "/pvp stats [player]" . TF::GRAY . " - View stats");
        $sender->sendMessage(TF::YELLOW . "/pvp top" . TF::GRAY . " - Top 10 players");
        
        if ($sender->hasPermission("pvpmode.admin")) {
            $sender->sendMessage(TF::GOLD . "━━━━━━━ Admin Commands ━━━━━━━");
            $sender->sendMessage(TF::RED . "/pvp kit" . TF::GRAY . " - Set duel kit");
            $sender->sendMessage(TF::RED . "/pvp set player1" . TF::GRAY . " - Set spawn 1");
            $sender->sendMessage(TF::RED . "/pvp set player2" . TF::GRAY . " - Set spawn 2");
        }
        
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    protected function onDisable(): void {
        $this->saveArenas();
    }
}

class RequestExpireTask extends Task {
    private Main $plugin;
    private string $targetName;

    public function __construct(Main $plugin, string $targetName) {
        $this->plugin = $plugin;
        $this->targetName = $targetName;
    }

    public function onRun(): void {
        $this->plugin->expireRequest($this->targetName);
    }
}

class DuelStartTask extends Task {
    private Player $p1;
    private Player $p2;

    public function __construct(Main $plugin, Player $p1, Player $p2) {
        $this->p1 = $p1;
        $this->p2 = $p2;
    }

    public function onRun(): void {
        $this->p1->sendMessage(TF::GREEN . "FIGHT!");
        $this->p2->sendMessage(TF::GREEN . "FIGHT!");
    }
}
