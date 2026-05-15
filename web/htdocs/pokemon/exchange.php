<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/DBUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/PokemonUtil.php");
	session_start();

    /**
     * Resolve the effective trade region pool for a specific game region from
     * a user trade_region_allowlist string.
     *
     * Example allowlist values:
     * - "e,f,d,s,i,p,u,j"  (national only)
     * - "efdsipu,j"        (Latin languages together, JP separate)
     * - "efdsipuj"         (all regions together)
     */
    function bxt_trade_regions_for_user_region($game_region, $allowlist) {
        $region = strtolower(trim((string)$game_region));
        if ($region === '') {
            return array();
        }

        $allow = strtolower(trim((string)$allowlist));
        if ($allow === '') {
            $allow = "efdsipuj";
        }

        $pool_for_region = "";
        $pools = explode(",", $allow);
        foreach ($pools as $pool) {
            $pool = strtolower(preg_replace('/[^a-z]/', '', (string)$pool));
            if ($pool === '') {
                continue;
            }
            if (strpos($pool, $region) !== false) {
                $pool_for_region = $pool;
                break;
            }
        }

        // Fallback for malformed / legacy values where the region-specific pool
        // cannot be located explicitly.
        if ($pool_for_region === '') {
            $fallback = strtolower(preg_replace('/[^a-z]/', '', $allow));
            if ($fallback !== '' && strpos($fallback, $region) !== false) {
                $pool_for_region = $fallback;
            } else {
                $pool_for_region = $region;
            }
        }

        return array_values(array_unique(str_split($pool_for_region)));
    }

    /**
     * Convert a game region code to the short game ID label used on the UI.
     */
    function bxt_trade_game_id_label($game_region) {
        switch (strtolower(trim((string)$game_region))) {
            case "e":
                return "ENG";
            case "u":
                return "AUS";
            case "p":
                return "EUR";
            case "d":
                return "GER";
            case "s":
                return "SPA";
            case "i":
                return "ITA";
            case "j":
                return "JPN";
            default:
                return strtoupper((string)$game_region);
        }
    }

    /**
     * Build the translation key for the exchange-card warning.
     */
    function bxt_trade_warning_key($game_region, $allowlist) {
        $allowed = bxt_trade_regions_for_user_region($game_region, $allowlist);

        if (count($allowed) <= 1) {
            return "pokemon.trade-warning-only-own-game";
        }

        if (!in_array("j", $allowed, true)) {
            return "pokemon.trade-warning-no-japanese";
        }

        return null;
    }

    /**
     * Convert DB timestamp values to Unix epoch seconds.
     */
    function bxt_exchange_timestamp_to_epoch($timestamp_value, $fallback_epoch) {
        if ($timestamp_value instanceof DateTimeInterface) {
            return $timestamp_value->getTimestamp();
        }
        if (is_numeric($timestamp_value)) {
            return intval($timestamp_value);
        }
        $parsed = strtotime((string)$timestamp_value);
        if ($parsed === false) {
            return intval($fallback_epoch);
        }
        return intval($parsed);
    }

    /**
     * Attach countdown metadata for the trade availability timer UI.
     */
    function bxt_exchange_apply_timer_meta(&$entry, $lifetime_seconds, $now_epoch) {
        $created_epoch = 0;
        if (isset($entry["timestamp_epoch"]) && is_numeric($entry["timestamp_epoch"])) {
            $created_epoch = intval($entry["timestamp_epoch"]);
        } else {
            $created_epoch = bxt_exchange_timestamp_to_epoch($entry["timestamp"] ?? null, $now_epoch);
        }
        if ($created_epoch <= 0) {
            $created_epoch = intval($now_epoch);
        }
        $entry["exchange_created_epoch"] = $created_epoch;
        $entry["exchange_lifetime_seconds"] = intval($lifetime_seconds);
        $entry["exchange_expire_epoch"] = $created_epoch + intval($lifetime_seconds);
    }

    $valid_regions = array("global", "j", "int", "eng", "e", "p", "u", "f", "d", "i", "s");

    $region = strtolower($_GET["region"] ?? "global");
    $region = in_array($region, $valid_regions, true) ? $region : "global";
    $pkm_util = PokemonUtil::getInstance();

    $db_util = DBUtil::getInstance();
    
    $db = $db_util->getDB();

    $trades = array();
    $exchange_lifetime_seconds = 7 * 24 * 60 * 60;
    $exchange_now_epoch = time();

    $max_rows = 20;

    $where_clause = "";
    if ($region == 'j') {
        $where_clause = "where bxt_exchange.game_region = 'j' ";
    } else if ($region == 'int') {
        $where_clause = "where bxt_exchange.game_region != 'j' ";
    } else if ($region == 'eng') {
        $where_clause = "where bxt_exchange.game_region IN ('e', 'p', 'u') ";
    } else if (in_array($region, array("e", "p", "u", "f", "d", "i", "s"), true)) {
        $where_clause = "where bxt_exchange.game_region = '" . $region . "' ";
    }

    $stmt = $db->prepare(
        "select " .
            "bxt_exchange.account_id, " .
            "bxt_exchange.trainer_id, " .
            "bxt_exchange.secret_id, " .
            "bxt_exchange.game_region, " .
            "bxt_exchange.offer_species, " .
            "bxt_exchange.offer_gender, " .
            "bxt_exchange.request_species, " .
            "bxt_exchange.request_gender, " .
            "bxt_exchange.player_name, " .
            "bxt_exchange.timestamp, " .
            "UNIX_TIMESTAMP(bxt_exchange.timestamp) AS timestamp_epoch, " .
            "bxt_exchange.pokemon, " .
            "bxt_exchange.mail, " .
            "sys_users.trade_region_allowlist " .
        "from bxt_exchange " .
        "join sys_users on bxt_exchange.account_id = sys_users.id " .
        $where_clause .
        "order by bxt_exchange.timestamp desc"
    );
    
    $genders = [null, "male", "female", null];
    $genders_symbols = [null, "♂", "♀", null];

    $stmt->execute();
    $data = DBUtil::fancy_get_result($stmt);
    foreach ($data as &$entry) {
        $pc = $entry['game_region'];
        $entry["player_name"] = $pkm_util->getString($pc, $entry['player_name']);
        $entry["pokemon"] = $pkm_util->unpackPokemon($pc, $entry["pokemon"]);
        //$entry["mail"] = $pkm_util->unpackMail($pc, $entry["mail"]);
        $entry["request"] = [
            "id" => $entry["request_species"],
            "name" => $pkm_util->getSpeciesName($entry["request_species"]),
            "gender" => $genders[$entry["request_gender"]],
            "gender_symbol" => $genders_symbols[$entry["request_gender"]],
        ];
        $entry["offer"] = [
            "id" => $entry["offer_species"],
            "name" => $pkm_util->getSpeciesName($entry["offer_species"]),
            "gender" => $genders[$entry["offer_gender"]],
            "gender_symbol" => $genders_symbols[$entry["offer_gender"]],
        ];
        $entry["trade_warning_key"] = bxt_trade_warning_key(
            $entry["game_region"],
            $entry["trade_region_allowlist"] ?? ""
        );
        $entry["trade_warning_game_id"] = bxt_trade_game_id_label($entry["game_region"]);
        bxt_exchange_apply_timer_meta($entry, $exchange_lifetime_seconds, $exchange_now_epoch);
        array_push($trades, $entry);
    }

    if (isset($_GET["add_fakes"])) {

        $pkm = $pkm_util->fakePokemon(6);
        $pkm["pokerus"]["cured"] = true;
        array_push($trades,[
            "pokemon" => $pkm,
            "player_name" => "REON",
            "game_region" => 'e',
            "request" => [
                "id" => 250,
                "name" => $pkm_util->getSpeciesName(250),
                "gender" => $genders[0],
                "gender_symbol" => $genders_symbols[0],
            ],
            "offer" => [
                "id" => $pkm["species"]["id"],
                "name" => $pkm["species"]["name"],
                "gender" => $genders[1],
                "gender_symbol" => $genders_symbols[1],
            ],
        ]);
        bxt_exchange_apply_timer_meta($trades[count($trades) - 1], $exchange_lifetime_seconds, $exchange_now_epoch);

        $pkm = $pkm_util->fakePokemon(197);
        array_push($trades,[
            "pokemon" => $pkm,
            "player_name" => "REON",
            "game_region" => 'e',
            "request" => [
                "id" => 200,
                "name" => $pkm_util->getSpeciesName(200),
                "gender" => $genders[2],
                "gender_symbol" => $genders_symbols[2],
            ],
            "offer" => [
                "id" => $pkm["species"]["id"],
                "name" => $pkm["species"]["name"],
                "gender" => $genders[2],
                "gender_symbol" => $genders_symbols[2],
            ],
        ]);
        bxt_exchange_apply_timer_meta($trades[count($trades) - 1], $exchange_lifetime_seconds, $exchange_now_epoch);
        end($trades)["pokemon"]["pokerus"]["cured"] = true;
    }

    echo TemplateUtil::render("/pokemon/exchange", [
        'trades' => $trades,
        'region_filter' => $region
    ]);
	
