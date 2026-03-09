<?php

declare(strict_types=1);

namespace icrafts\iconomybedrock;

use mysqli;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\EventHandler;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use function abs;
use function array_filter;
use function array_key_exists;
use function array_slice;
use function ceil;
use function class_exists;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function sprintf;
use function strtolower;
use function trim;
use function uasort;

final class iConomyBedrock extends PluginBase implements Listener
{
    private string $storageType = "local";
    private string $mysqlTable = "iconomy_accounts";
    private ?Config $accounts = null;
    private ?mysqli $mysql = null;

    public function onEnable(): void
    {
        $this->saveResource("config.yml");
        $this->saveResource("accounts.yml");
        $this->ensureStorageConfigDefaults();

        $configuredStorageType = strtolower(
            (string) $this->getConfig()->getNested(
                "system.storage.type",
                "local",
            ),
        );
        $this->storageType = in_array(
            $configuredStorageType,
            ["local", "mysql"],
            true,
        )
            ? $configuredStorageType
            : "local";
        if ($this->storageType !== $configuredStorageType) {
            $this->getLogger()->warning(
                "Unknown storage type '{$configuredStorageType}'. Falling back to local.",
            );
        }

        if ($this->storageType === "mysql") {
            if (!class_exists(mysqli::class)) {
                $this->getLogger()->critical(
                    "MySQL storage requested, but mysqli extension is not available.",
                );
                $this->getServer()->getPluginManager()->disablePlugin($this);
                return;
            }
            if (!$this->setupMysqlStorage()) {
                $this->getLogger()->critical(
                    "Could not initialize MySQL storage. Plugin disabled.",
                );
                $this->getServer()->getPluginManager()->disablePlugin($this);
                return;
            }
        } else {
            $this->storageType = "local";
            $this->accounts = new Config(
                $this->getDataFolder() . "accounts.yml",
                Config::YAML,
                ["accounts" => []],
            );
            if (!is_array($this->accounts->get("accounts"))) {
                $this->accounts->set("accounts", []);
                $this->accounts->save();
            }
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info(
            "iConomyBedrock enabled (storage: {$this->storageType}).",
        );
    }

    private function ensureStorageConfigDefaults(): void
    {
        $config = $this->getConfig();
        $changed = false;

        if ($config->getNested("system.storage.type") === null) {
            $config->setNested("system.storage.type", "local");
            $changed = true;
        }
        if ($config->getNested("system.storage.mysql.host") === null) {
            $config->setNested("system.storage.mysql.host", "127.0.0.1");
            $changed = true;
        }
        if ($config->getNested("system.storage.mysql.port") === null) {
            $config->setNested("system.storage.mysql.port", 3306);
            $changed = true;
        }
        if ($config->getNested("system.storage.mysql.database") === null) {
            $config->setNested("system.storage.mysql.database", "icrafts");
            $changed = true;
        }
        if ($config->getNested("system.storage.mysql.username") === null) {
            $config->setNested("system.storage.mysql.username", "root");
            $changed = true;
        }
        if ($config->getNested("system.storage.mysql.password") === null) {
            $config->setNested("system.storage.mysql.password", "");
            $changed = true;
        }
        if ($config->getNested("system.storage.mysql.table") === null) {
            $config->setNested(
                "system.storage.mysql.table",
                "iconomy_accounts",
            );
            $changed = true;
        }
        if ($config->getNested("system.formatting.prefix") === null) {
            $config->setNested("system.formatting.prefix", "&6[iConomy]&r ");
            $changed = true;
        }

        if ($changed) {
            $config->save();
        }
    }

    public function onDisable(): void
    {
        if ($this->storageType === "local" && $this->accounts !== null) {
            $this->accounts->save();
        }
        if ($this->mysql !== null) {
            $this->mysql->close();
            $this->mysql = null;
        }
    }

    #[EventHandler]
    public function onPlayerJoin(PlayerJoinEvent $event): void
    {
        $this->ensureAccount($event->getPlayer()->getName());
    }

    public function onCommand(
        CommandSender $sender,
        Command $command,
        string $label,
        array $args,
    ): bool {
        $name = strtolower($command->getName());
        if ($name === "money" || $name === "bal" || $name === "balance") {
            return $this->handleMoney($sender, $args);
        }
        if ($name === "bank") {
            return $this->handleBank($sender, $args);
        }
        if ($name === "icoimport") {
            $this->msg(
                $sender,
                "&e/icoimport не поддерживается в Bedrock-версии. Импортируйте данные вручную в accounts.yml.",
            );
            return true;
        }

        return false;
    }

    private function handleMoney(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("iconomy.access")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }

        if ($args === []) {
            if (!$sender instanceof Player) {
                $this->msg(
                    $sender,
                    "&cИспользуйте /money <игрок> из консоли или /money help.",
                );
                return true;
            }
            $this->showBalance($sender, $sender->getName());
            return true;
        }

        $sub = strtolower((string) $args[0]);
        return match ($sub) {
            "help", "?" => $this->moneyHelp($sender),
            "pay" => $this->moneyPay($sender, $args),
            "top" => $this->moneyTop($sender, $args),
            "rank" => $this->moneyRank($sender, $args),
            "grant" => $this->moneyGrant($sender, $args),
            "set" => $this->moneySet($sender, $args),
            "create" => $this->moneyCreate($sender, $args),
            "remove" => $this->moneyRemove($sender, $args),
            "reset" => $this->moneyReset($sender, $args),
            "hide" => $this->moneyHide($sender, $args),
            "stats" => $this->moneyStats($sender),
            "purge" => $this->moneyPurge($sender),
            "empty" => $this->moneyEmpty($sender),
            default => $this->moneyLookupOrHelp($sender, $args),
        };
    }

    private function handleBank(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("iconomy.bank.access")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        if (!$this->isBankingEnabled()) {
            $this->msg($sender, "&cБанковская система отключена в конфиге.");
            return true;
        }
        if (!$sender instanceof Player) {
            $this->msg($sender, "&cКоманда /bank доступна только игроку.");
            return true;
        }

        $name = $sender->getName();
        $this->ensureAccount($name);
        if ($args === []) {
            $this->msg(
                $sender,
                "&aБаланс банка: &e" .
                    $this->moneyString($this->getBank($name)),
            );
            return true;
        }

        $sub = strtolower((string) $args[0]);
        if ($sub === "help" || $sub === "?") {
            $this->msg($sender, "&a/bank - показать баланс банка");
            $this->msg($sender, "&a/bank deposit <сумма>");
            $this->msg($sender, "&a/bank withdraw <сумма>");
            $this->msg($sender, "&a/bank send <игрок> <сумма>");
            return true;
        }

        if ($sub === "deposit") {
            if (!$sender->hasPermission("iconomy.bank.deposit")) {
                $this->msg($sender, "&cУ вас нет прав на эту команду.");
                return true;
            }
            $amount = $this->parseAmount($args[1] ?? null);
            if ($amount === null || $amount <= 0.0) {
                $this->msg($sender, "&cИспользование: /bank deposit <сумма>");
                return true;
            }
            if ($this->getBalance($name) < $amount) {
                $this->msg($sender, "&cНедостаточно денег на руках.");
                return true;
            }
            $this->setBalance($name, $this->getBalance($name) - $amount);
            $this->setBank($name, $this->getBank($name) + $amount);
            $this->msg(
                $sender,
                "&aВнесено в банк: &e" . $this->moneyString($amount),
            );
            return true;
        }

        if ($sub === "withdraw") {
            if (!$sender->hasPermission("iconomy.bank.withdraw")) {
                $this->msg($sender, "&cУ вас нет прав на эту команду.");
                return true;
            }
            $amount = $this->parseAmount($args[1] ?? null);
            if ($amount === null || $amount <= 0.0) {
                $this->msg($sender, "&cИспользование: /bank withdraw <сумма>");
                return true;
            }
            if ($this->getBank($name) < $amount) {
                $this->msg($sender, "&cНедостаточно денег в банке.");
                return true;
            }
            $this->setBank($name, $this->getBank($name) - $amount);
            $this->setBalance($name, $this->getBalance($name) + $amount);
            $this->msg(
                $sender,
                "&aСнято из банка: &e" . $this->moneyString($amount),
            );
            return true;
        }

        if ($sub === "send") {
            if (!$sender->hasPermission("iconomy.bank.transfer")) {
                $this->msg($sender, "&cУ вас нет прав на эту команду.");
                return true;
            }
            if (count($args) < 3) {
                $this->msg(
                    $sender,
                    "&cИспользование: /bank send <игрок> <сумма>",
                );
                return true;
            }
            $target = $this->normalizeName((string) $args[1]);
            $amount = $this->parseAmount($args[2] ?? null);
            if ($amount === null || $amount <= 0.0) {
                $this->msg($sender, "&cСумма должна быть больше нуля.");
                return true;
            }
            if ($target === $this->normalizeName($name)) {
                $this->msg($sender, "&cНельзя отправить деньги самому себе.");
                return true;
            }
            if ($this->getBank($name) < $amount) {
                $this->msg($sender, "&cНедостаточно денег в банке.");
                return true;
            }
            $this->ensureAccount($target);
            $this->setBank($name, $this->getBank($name) - $amount);
            $this->setBank($target, $this->getBank($target) + $amount);
            $this->msg(
                $sender,
                "&aОтправлено &e" .
                    $this->moneyString($amount) .
                    "&a в банк игрока &e{$target}&a.",
            );
            return true;
        }

        $this->msg(
            $sender,
            "&cНеизвестная подкоманда /bank. Используйте /bank help",
        );
        return true;
    }

    private function moneyHelp(CommandSender $sender): bool
    {
        $this->msg($sender, "&a/money - показать ваш баланс");
        $this->msg($sender, "&a/money <player> - показать баланс игрока");
        $this->msg($sender, "&a/money pay <player> <amount>");
        $this->msg($sender, "&a/money top [count]");
        $this->msg($sender, "&a/money rank [игрок]");
        if ($sender->hasPermission("iconomy.admin.grant")) {
            $this->msg($sender, "&a/money grant <player> <amount>");
        }
        if ($sender->hasPermission("iconomy.admin.set")) {
            $this->msg($sender, "&a/money set <player> <amount>");
        }
        if ($sender->hasPermission("iconomy.admin")) {
            $this->msg(
                $sender,
                "&aАдмин: create/remove/reset/hide/stats/purge/empty",
            );
        }
        return true;
    }

    private function moneyLookupOrHelp(CommandSender $sender, array $args): bool
    {
        if (count($args) === 1) {
            $this->showBalance($sender, (string) $args[0]);
            return true;
        }
        return $this->moneyHelp($sender);
    }

    private function moneyPay(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $this->msg($sender, "&cЭта команда доступна только игроку.");
            return true;
        }
        if (!$sender->hasPermission("iconomy.payment")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        if (count($args) < 3) {
            $this->msg($sender, "&cИспользование: /money pay <игрок> <сумма>");
            return true;
        }

        $from = $sender->getName();
        $to = $this->normalizeName((string) $args[1]);
        $amount = $this->parseAmount($args[2] ?? null);

        if ($amount === null || $amount <= 0.0) {
            $this->msg($sender, "&cСумма должна быть больше нуля.");
            return true;
        }
        if ($to === $this->normalizeName($from)) {
            $this->msg($sender, "&cНельзя перевести деньги самому себе.");
            return true;
        }
        if ($this->getBalance($from) < $amount) {
            $this->msg($sender, "&cНедостаточно денег.");
            return true;
        }

        $this->ensureAccount($to);
        $this->setBalance($from, $this->getBalance($from) - $amount);
        $this->setBalance($to, $this->getBalance($to) + $amount);
        $this->msg(
            $sender,
            "&aВы перевели игроку &e{$to}&a: &e" . $this->moneyString($amount),
        );

        $online = $this->getServer()->getPlayerExact($to);
        if ($online instanceof Player) {
            $this->msg(
                $online,
                "&aВы получили от &e{$from}&a: &e" .
                    $this->moneyString($amount),
            );
        }
        return true;
    }

    private function moneyTop(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("iconomy.list")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        $countArg = $args[1] ?? null;
        $count = $this->getTopDefaultCount();
        if ($countArg !== null && is_numeric((string) $countArg)) {
            $count = (int) $countArg;
            if ($count < 1) {
                $count = 1;
            }
            if ($count > 50) {
                $count = 50;
            }
        }

        $accounts = $this->getAccounts();
        $visible = array_filter(
            $accounts,
            fn(array $data): bool => !((bool) ($data["hidden"] ?? false)),
        );
        uasort(
            $visible,
            fn(array $a, array $b): int => ($b["balance"] ?? 0.0) <=>
                ($a["balance"] ?? 0.0),
        );
        $slice = array_slice($visible, 0, $count, true);

        $this->msg($sender, "&aТоп {$count} аккаунтов:");
        $index = 1;
        foreach ($slice as $name => $data) {
            $this->msg(
                $sender,
                "&e#{$index} &f{$name}&7 - &a" .
                    $this->moneyString((float) ($data["balance"] ?? 0.0)),
            );
            $index++;
        }
        if ($slice === []) {
            $this->msg($sender, "&7Аккаунтов нет.");
        }
        return true;
    }

    private function moneyRank(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("iconomy.rank")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        $target = isset($args[1])
            ? $this->normalizeName((string) $args[1])
            : ($sender instanceof Player
                ? $this->normalizeName($sender->getName())
                : "");
        if ($target === "") {
            $this->msg($sender, "&cИспользование: /money rank <игрок>");
            return true;
        }
        $this->ensureAccount($target);

        $accounts = $this->getAccounts();
        $visible = array_filter(
            $accounts,
            fn(array $data): bool => !((bool) ($data["hidden"] ?? false)),
        );
        uasort(
            $visible,
            fn(array $a, array $b): int => ($b["balance"] ?? 0.0) <=>
                ($a["balance"] ?? 0.0),
        );

        $rank = 0;
        $index = 1;
        foreach ($visible as $name => $_data) {
            if ($this->normalizeName((string) $name) === $target) {
                $rank = $index;
                break;
            }
            $index++;
        }
        if ($rank === 0) {
            $this->msg(
                $sender,
                "&c{$target} скрыт или отсутствует в рейтинге.",
            );
            return true;
        }
        $this->msg(
            $sender,
            "&aМесто игрока &e{$target}&a: &e#{$rank}&a / &e" . count($visible),
        );
        return true;
    }

    private function moneyGrant(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("iconomy.admin.grant")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        if (count($args) < 3) {
            $this->msg(
                $sender,
                "&cИспользование: /money grant <игрок> <сумма>",
            );
            return true;
        }
        $target = $this->normalizeName((string) $args[1]);
        $amount = $this->parseAmount($args[2] ?? null);
        if ($amount === null || $amount == 0.0) {
            $this->msg($sender, "&cСумма не может быть равна нулю.");
            return true;
        }
        $this->ensureAccount($target);
        $next = $this->getBalance($target) + $amount;
        if ($next < 0.0) {
            $next = 0.0;
        }
        $this->setBalance($target, $next);
        $this->msg(
            $sender,
            "&aБаланс игрока &e{$target}&a обновлён: &e" .
                $this->moneyString($next),
        );
        return true;
    }

    private function moneySet(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("iconomy.admin.set")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        if (count($args) < 3) {
            $this->msg($sender, "&cИспользование: /money set <игрок> <сумма>");
            return true;
        }
        $target = $this->normalizeName((string) $args[1]);
        $amount = $this->parseAmount($args[2] ?? null);
        if ($amount === null || $amount < 0.0) {
            $this->msg($sender, "&cСумма должна быть >= 0.");
            return true;
        }
        $this->ensureAccount($target);
        $this->setBalance($target, $amount);
        $this->msg(
            $sender,
            "&aБаланс игрока &e{$target}&a установлен: &e" .
                $this->moneyString($amount),
        );
        return true;
    }

    private function moneyCreate(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("iconomy.admin.account.create")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        if (count($args) < 2) {
            $this->msg($sender, "&cИспользование: /money create <игрок>");
            return true;
        }
        $target = $this->normalizeName((string) $args[1]);
        if ($this->accountExists($target)) {
            $this->msg($sender, "&cАккаунт &e{$target}&c уже существует.");
            return true;
        }
        $this->ensureAccount($target);
        $this->msg($sender, "&aСоздан аккаунт для &e{$target}&a.");
        return true;
    }

    private function moneyRemove(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("iconomy.admin.account.remove")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        if (count($args) < 2) {
            $this->msg($sender, "&cИспользование: /money remove <игрок>");
            return true;
        }
        $target = $this->normalizeName((string) $args[1]);
        $accounts = $this->getAccounts();
        if (!array_key_exists($target, $accounts)) {
            $this->msg($sender, "&cАккаунт не найден: &e{$target}");
            return true;
        }
        unset($accounts[$target]);
        $this->setAccounts($accounts);
        $this->msg($sender, "&aАккаунт игрока &e{$target}&a удалён.");
        return true;
    }

    private function moneyReset(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("iconomy.admin.reset")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        if (count($args) < 2) {
            $this->msg($sender, "&cИспользование: /money reset <игрок>");
            return true;
        }
        $target = $this->normalizeName((string) $args[1]);
        $this->ensureAccount($target);
        $this->setBalance($target, $this->defaultHoldings());
        $this->setBank($target, $this->defaultBankHoldings());
        $this->msg($sender, "&aАккаунт игрока &e{$target}&a сброшен.");
        return true;
    }

    private function moneyHide(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("iconomy.admin.hide")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        if (count($args) < 3) {
            $this->msg(
                $sender,
                "&cИспользование: /money hide <игрок> <true|false>",
            );
            return true;
        }
        $target = $this->normalizeName((string) $args[1]);
        $value = strtolower((string) $args[2]);
        if (!in_array($value, ["true", "false", "on", "off", "1", "0"], true)) {
            $this->msg($sender, "&cИспользуйте true/false.");
            return true;
        }
        $hidden = in_array($value, ["true", "on", "1"], true);
        $this->ensureAccount($target);
        $data = $this->getAccountData($target);
        $data["hidden"] = $hidden;
        $this->setAccountData($target, $data);
        $this->msg(
            $sender,
            "&aСкрытие аккаунта &e{$target}&a установлено в &e" .
                ($hidden ? "true" : "false"),
        );
        return true;
    }

    private function moneyStats(CommandSender $sender): bool
    {
        if (!$sender->hasPermission("iconomy.admin.stats")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        $accounts = $this->getAccounts();
        $totalAccounts = count($accounts);
        $totalMoney = 0.0;
        $totalBank = 0.0;
        foreach ($accounts as $data) {
            $totalMoney += (float) ($data["balance"] ?? 0.0);
            $totalBank += (float) ($data["bank"] ?? 0.0);
        }
        $this->msg($sender, "&aАккаунтов: &e{$totalAccounts}");
        $this->msg(
            $sender,
            "&aДенег на руках: &e" . $this->moneyString($totalMoney),
        );
        $this->msg(
            $sender,
            "&aДенег в банке: &e" . $this->moneyString($totalBank),
        );
        return true;
    }

    private function moneyPurge(CommandSender $sender): bool
    {
        if (!$sender->hasPermission("iconomy.admin.purge")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        $accounts = $this->getAccounts();
        $removed = 0;
        foreach ($accounts as $name => $data) {
            $balance = (float) ($data["balance"] ?? 0.0);
            $hidden = (bool) ($data["hidden"] ?? false);
            if (
                !$hidden &&
                abs($balance - $this->defaultHoldings()) < 0.00001 &&
                (float) ($data["bank"] ?? 0.0) <= 0.0
            ) {
                unset($accounts[$name]);
                $removed++;
            }
        }
        $this->setAccounts($accounts);
        $this->msg($sender, "&aОчищено аккаунтов: &e{$removed}");
        return true;
    }

    private function moneyEmpty(CommandSender $sender): bool
    {
        if (!$sender->hasPermission("iconomy.admin.empty")) {
            $this->msg($sender, "&cУ вас нет прав на эту команду.");
            return true;
        }
        $this->setAccounts([]);
        $this->msg($sender, "&cВсе аккаунты удалены.");
        return true;
    }

    private function showBalance(CommandSender $sender, string $player): void
    {
        $target = $this->normalizeName($player);
        $this->ensureAccount($target);
        $this->msg(
            $sender,
            "&aБаланс игрока &e{$target}&a: &e" .
                $this->moneyString($this->getBalance($target)),
        );
    }

    private function parseAmount(mixed $raw): ?float
    {
        if ($raw === null || !is_numeric((string) $raw)) {
            return null;
        }
        return (float) $raw;
    }

    private function defaultHoldings(): float
    {
        return (float) $this->getConfig()->getNested(
            "system.default.account.holdings",
            30.0,
        );
    }

    private function defaultBankHoldings(): float
    {
        return (float) $this->getConfig()->getNested(
            "system.default.account.bank_holdings",
            0.0,
        );
    }

    private function getTopDefaultCount(): int
    {
        $value = (int) $this->getConfig()->getNested(
            "system.top.default_count",
            10,
        );
        return $value > 0 ? $value : 10;
    }

    private function isBankingEnabled(): bool
    {
        return (bool) $this->getConfig()->getNested(
            "system.banking.enabled",
            true,
        );
    }

    private function moneyString(float $amount): string
    {
        $decimals = (int) $this->getConfig()->getNested(
            "system.formatting.decimals",
            2,
        );
        if ($decimals < 0) {
            $decimals = 0;
        }
        if ($decimals > 6) {
            $decimals = 6;
        }
        $formatted = number_format($amount, $decimals, ".", "");
        $single = (string) $this->getConfig()->getNested(
            "system.default.currency.major.single",
            "Dollar",
        );
        $plural = (string) $this->getConfig()->getNested(
            "system.default.currency.major.plural",
            "Dollars",
        );
        $unit = abs($amount - 1.0) < 0.00001 ? $single : $plural;
        return $formatted . " " . $unit;
    }

    private function normalizeName(string $name): string
    {
        return strtolower(trim($name));
    }

    private function ensureAccount(string $name): void
    {
        $name = $this->normalizeName($name);
        if ($name === "") {
            return;
        }
        $accounts = $this->getAccounts();
        if (
            !array_key_exists($name, $accounts) ||
            !is_array($accounts[$name])
        ) {
            $accounts[$name] = [
                "balance" => $this->defaultHoldings(),
                "bank" => $this->defaultBankHoldings(),
                "hidden" => false,
            ];
            $this->setAccounts($accounts);
        }
    }

    private function accountExists(string $name): bool
    {
        $name = $this->normalizeName($name);
        if ($name === "") {
            return false;
        }
        if ($this->storageType === "mysql") {
            $data = $this->getAccountData($name);
            return $data !== [];
        }
        $accounts = $this->getAccounts();
        return array_key_exists($name, $accounts);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAccounts(): array
    {
        if ($this->storageType === "mysql") {
            return $this->mysqlGetAccounts();
        }
        if ($this->accounts === null) {
            return [];
        }
        $accounts = $this->accounts->get("accounts", []);
        return is_array($accounts) ? $accounts : [];
    }

    /**
     * @param array<string, array<string, mixed>> $accounts
     */
    private function setAccounts(array $accounts): void
    {
        if ($this->storageType === "mysql") {
            $this->mysqlSetAccounts($accounts);
            return;
        }
        if ($this->accounts === null) {
            return;
        }
        $this->accounts->set("accounts", $accounts);
        $this->accounts->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function getAccountData(string $name): array
    {
        $name = $this->normalizeName($name);
        if ($this->storageType === "mysql") {
            return $this->mysqlGetAccountData($name);
        }
        $accounts = $this->getAccounts();
        $data = $accounts[$name] ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setAccountData(string $name, array $data): void
    {
        $name = $this->normalizeName($name);
        if ($this->storageType === "mysql") {
            $this->mysqlSetAccountData($name, $data);
            return;
        }
        $accounts = $this->getAccounts();
        $accounts[$name] = $data;
        $this->setAccounts($accounts);
    }

    private function getBalance(string $name): float
    {
        $this->ensureAccount($name);
        return (float) ($this->getAccountData($name)["balance"] ?? 0.0);
    }

    private function setBalance(string $name, float $value): void
    {
        $data = $this->getAccountData($name);
        $data["balance"] = $value < 0.0 ? 0.0 : $value;
        $this->setAccountData($name, $data);
    }

    private function getBank(string $name): float
    {
        $this->ensureAccount($name);
        return (float) ($this->getAccountData($name)["bank"] ?? 0.0);
    }

    private function setBank(string $name, float $value): void
    {
        $data = $this->getAccountData($name);
        $data["bank"] = $value < 0.0 ? 0.0 : $value;
        $this->setAccountData($name, $data);
    }

    private function msg(CommandSender $sender, string $text): void
    {
        $prefix = (string) $this->getConfig()->getNested(
            "system.formatting.prefix",
            "&6[iConomy]&r ",
        );
        $sender->sendMessage(TextFormat::colorize($prefix . $text));
    }

    private function setupMysqlStorage(): bool
    {
        $host = (string) $this->getConfig()->getNested(
            "system.storage.mysql.host",
            "127.0.0.1",
        );
        $port = (int) $this->getConfig()->getNested(
            "system.storage.mysql.port",
            3306,
        );
        $database = (string) $this->getConfig()->getNested(
            "system.storage.mysql.database",
            "",
        );
        $username = (string) $this->getConfig()->getNested(
            "system.storage.mysql.username",
            "",
        );
        $password = (string) $this->getConfig()->getNested(
            "system.storage.mysql.password",
            "",
        );
        $table = (string) $this->getConfig()->getNested(
            "system.storage.mysql.table",
            "iconomy_accounts",
        );
        $this->mysqlTable =
            trim($table) !== "" ? trim($table) : "iconomy_accounts";

        try {
            $this->mysql = @new mysqli(
                $host,
                $username,
                $password,
                $database,
                $port,
            );
            if ($this->mysql->connect_errno !== 0) {
                $this->getLogger()->critical(
                    "MySQL connection failed: " . $this->mysql->connect_error,
                );
                $this->mysql = null;
                return false;
            }
            $this->mysql->set_charset("utf8mb4");
            $sql = sprintf(
                "CREATE TABLE IF NOT EXISTS `%s` (" .
                    "`name` VARCHAR(32) NOT NULL PRIMARY KEY," .
                    "`balance` DOUBLE NOT NULL DEFAULT 0," .
                    "`bank` DOUBLE NOT NULL DEFAULT 0," .
                    "`hidden` TINYINT(1) NOT NULL DEFAULT 0" .
                    ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
                $this->mysql->real_escape_string($this->mysqlTable),
            );
            $ok = $this->mysql->query($sql);
            if ($ok === false) {
                $this->getLogger()->critical(
                    "MySQL table init failed: " . $this->mysql->error,
                );
                return false;
            }
        } catch (\Throwable $e) {
            $this->getLogger()->critical(
                "MySQL setup exception: " . $e->getMessage(),
            );
            $this->mysql = null;
            return false;
        }
        return true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mysqlGetAccounts(): array
    {
        if ($this->mysql === null) {
            return [];
        }
        $accounts = [];
        $sql = sprintf(
            "SELECT `name`, `balance`, `bank`, `hidden` FROM `%s`;",
            $this->mysql->real_escape_string($this->mysqlTable),
        );
        $result = $this->mysql->query($sql);
        if ($result === false) {
            $this->getLogger()->error(
                "MySQL read accounts failed: " . $this->mysql->error,
            );
            return [];
        }
        while ($row = $result->fetch_assoc()) {
            $name = strtolower((string) ($row["name"] ?? ""));
            if ($name === "") {
                continue;
            }
            $accounts[$name] = [
                "balance" => (float) ($row["balance"] ?? 0.0),
                "bank" => (float) ($row["bank"] ?? 0.0),
                "hidden" => ((int) ($row["hidden"] ?? 0)) === 1,
            ];
        }
        $result->free();
        return $accounts;
    }

    /**
     * @param array<string, array<string, mixed>> $accounts
     */
    private function mysqlSetAccounts(array $accounts): void
    {
        if ($this->mysql === null) {
            return;
        }
        $table = $this->mysql->real_escape_string($this->mysqlTable);
        $this->mysql->begin_transaction();
        try {
            $this->mysql->query(sprintf("TRUNCATE TABLE `%s`;", $table));
            $stmt = $this->mysql->prepare(
                sprintf(
                    "INSERT INTO `%s` (`name`, `balance`, `bank`, `hidden`) VALUES (?, ?, ?, ?);",
                    $table,
                ),
            );
            if ($stmt === false) {
                throw new \RuntimeException($this->mysql->error);
            }
            foreach ($accounts as $name => $data) {
                $name = $this->normalizeName((string) $name);
                if ($name === "") {
                    continue;
                }
                $balance = (float) ($data["balance"] ?? 0.0);
                $bank = (float) ($data["bank"] ?? 0.0);
                $hidden = (bool) ($data["hidden"] ?? false) ? 1 : 0;
                $stmt->bind_param("sddi", $name, $balance, $bank, $hidden);
                $stmt->execute();
            }
            $stmt->close();
            $this->mysql->commit();
        } catch (\Throwable $e) {
            $this->mysql->rollback();
            $this->getLogger()->error(
                "MySQL write accounts failed: " . $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mysqlGetAccountData(string $name): array
    {
        if ($this->mysql === null || $name === "") {
            return [];
        }
        $table = $this->mysql->real_escape_string($this->mysqlTable);
        $stmt = $this->mysql->prepare(
            sprintf(
                "SELECT `balance`, `bank`, `hidden` FROM `%s` WHERE `name` = ? LIMIT 1;",
                $table,
            ),
        );
        if ($stmt === false) {
            $this->getLogger()->error(
                "MySQL prepare failed: " . $this->mysql->error,
            );
            return [];
        }
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            return [];
        }
        return [
            "balance" => (float) ($row["balance"] ?? 0.0),
            "bank" => (float) ($row["bank"] ?? 0.0),
            "hidden" => ((int) ($row["hidden"] ?? 0)) === 1,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mysqlSetAccountData(string $name, array $data): void
    {
        if ($this->mysql === null || $name === "") {
            return;
        }
        $table = $this->mysql->real_escape_string($this->mysqlTable);
        $balance = (float) ($data["balance"] ?? 0.0);
        $bank = (float) ($data["bank"] ?? 0.0);
        $hiddenRaw = $data["hidden"] ?? false;
        $hidden = (is_bool($hiddenRaw)
                ? $hiddenRaw
                : (bool) $hiddenRaw)
            ? 1
            : 0;
        $stmt = $this->mysql->prepare(
            sprintf(
                "INSERT INTO `%s` (`name`, `balance`, `bank`, `hidden`) VALUES (?, ?, ?, ?) " .
                    "ON DUPLICATE KEY UPDATE `balance` = VALUES(`balance`), `bank` = VALUES(`bank`), `hidden` = VALUES(`hidden`);",
                $table,
            ),
        );
        if ($stmt === false) {
            $this->getLogger()->error(
                "MySQL prepare failed: " . $this->mysql->error,
            );
            return;
        }
        $stmt->bind_param("sddi", $name, $balance, $bank, $hidden);
        $stmt->execute();
        $stmt->close();
    }
}
