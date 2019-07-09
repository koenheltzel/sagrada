<?php
/**
 *------
 * BGA framework: © Gregory Isabelli <gisabelli@boardgamearena.com> & Emmanuel Colin <ecolin@boardgamearena.com>
 * Sagrada implementation : © Koen Heltzel koenheltzel@gmail.com
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * sagrada.game.php
 *
 * This is the main file for your game logic.
 *
 * In this PHP file, you are going to defines the rules of the game.
 *
 */

use Sagrada\Colors;

require_once(APP_GAMEMODULE_PATH . 'module/table/table.game.php');
if (0) require_once '_bga_ide_helper.php';

// Load all modules:
foreach (['Board', 'BoardSpace', 'Color', 'Colors', 'Die_', 'Pattern', 'Patterns'] as $class) {
    require_once(dirname(__FILE__) . "/modules/{$class}.php");
}

class Sagrada extends Table {

    const PATTERNS_PER_PLAYER = 4;
    const GAMESTATE_DICEBAG = "dicebag_";
    const GAMESTATE_PUBLICOBJECTIVES = "publicobjectives";

    function __construct() {
        // Your global variables labels:
        //  Here, you can assign labels to global variables you are using for this game.
        //  You can use any number of global variables with IDs between 10 and 99.
        //  If your game has options (variants), you also have to associate here a label to
        //  the corresponding ID in gameoptions.inc.php.
        // Note: afterwards, you can get/set the global variables with getGameStateValue/setGameStateInitialValue/setGameStateValue
        parent::__construct();

        self::initGameStateLabels([
                static::GAMESTATE_DICEBAG . "R" => 10,
                static::GAMESTATE_DICEBAG . "G" => 11,
                static::GAMESTATE_DICEBAG . "B" => 12,
                static::GAMESTATE_DICEBAG . "Y" => 13,
                static::GAMESTATE_DICEBAG . "P" => 14,
                static::GAMESTATE_PUBLICOBJECTIVES => 15,
            //      ...
            //    "my_first_game_variant" => 100,
            //    "my_second_game_variant" => 101,
            //      ...
        ]);
    }

    /**
     * This is the generic method to access the database.
     * It can execute any type of SELECT/UPDATE/DELETE/REPLACE query on the database.
     * You should use it for UPDATE/DELETE/REPLACE query. For SELECT queries, the specialized methods below are much better.
     *
     * @param $sql
     * @return mysqli_result
     */
    public static function db($sql) {
        return self::DbQuery($sql);
    }

    protected function getGameName() {
        // Used for translations and stuff. Please do not modify.
        return "sagrada";
    }

    /*
        setupNewGame:
        
        This method is called only once, when a new game is launched.
        In this method, you must setup the game according to the game rules, so that
        the game is ready to be played.
    */
    protected function setupNewGame($players, $options = []) {
        // Set the colors of the players with HTML color code
        // The default below is red/green/blue/orange/brown
        // The number of colors defined here must correspond to the maximum number of players allowed for the gams
        $gameinfos = self::getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        // Create players
        // Note: if you added some extra field on "player" table in the database (dbmodel.sql), you can initialize it there.
        $sql = "INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES ";
        $values = [];
        foreach ($players as $player_id => $player) {
            $color = array_shift($default_colors);
            $values[] = "('" . $player_id . "','$color','" . $player['player_canal'] . "','" . addslashes($player['player_name']) . "','" . addslashes($player['player_avatar']) . "')";
        }
        $sql .= implode($values, ',');
        self::DbQuery($sql);
        self::reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        self::reloadPlayersBasicInfos();

        /************ Start the game initialization *****/

        // Init global values with their initial values
        //self::setGameStateInitialValue( 'my_first_global_variable', 0 );

        // Init game statistics
        // (note: statistics used in this file must be defined in your stats.inc.php file)
        //self::initStat( 'table', 'table_teststat1', 0 );    // Init a table statistics
        //self::initStat( 'player', 'player_teststat1', 0 );  // Init a player statistics (for all players)

        // TODO: setup the initial game situation here

        $this->sagradaSetupNewGame();


        // Activate first player (which is in general a good idea :) )
        $this->activeNextPlayer();

        /************ End of the game initialization *****/
    }

    private function sagradaSetupNewGame() {
        // Fill the dice bag.
        foreach (Colors::get()->colors as $color) {
            self::setGameStateInitialValue(static::GAMESTATE_DICEBAG . $color->char, 18);
        }

        $players = static::db("SELECT * FROM player ORDER BY player_no")->fetch_all(MYSQLI_ASSOC);
        $playerCount = count($players);
        $patternCount = count($players) * static::PATTERNS_PER_PLAYER;
        $pairCount = $patternCount / 2;

        $publicObjectivesCount = count($players) > 1 ? 3 : 2; // Normally 3 public objectives are assigned, but in a solo game 2.
        $publicObjectives = static::db("SELECT * FROM sag_publicobjectives ORDER BY RAND() LIMIT {$publicObjectivesCount}")->fetch_all(MYSQLI_ASSOC);
        $publicObjectivesIds = array_map(function($publicObjective) { return $publicObjective['id'] ;}, $publicObjectives );
        $publicObjectivesIdsString = implode(',', $publicObjectivesIds);
        self::setGameStateInitialValue(static::GAMESTATE_PUBLICOBJECTIVES, $publicObjectivesIdsString);

        // We select the patterns by the pair, because in the real game the patterns are on double sided cards. Don't know if the creators care about preserving these pairs, but BGA strives for authenticity, so there you go.
        $pairs = static::db("SELECT DISTINCT pair FROM sag_patterns ORDER BY RAND() LIMIT {$pairCount}")->fetch_all();
        $pairIds = implode(',', array_map(function($pair) { return $pair[0] ;}, $pairs));
        // Select the patterns, ordered by the random selected pairs above (and within the pair, sort random).
        $sql = "SELECT * FROM sag_patterns WHERE pair IN ({$pairIds}) ORDER BY FIELD(pair, {$pairIds}), RAND() LIMIT {$patternCount}";
        $patterns = static::db($sql)->fetch_all(MYSQLI_ASSOC);

        // Select private objective colors.
        $randomColors = null;
        $randomColors = Colors::get()->colors;
        shuffle($randomColors);
        $randomColorChars = array_map(function($randomColor) { return $randomColor->char;}, $randomColors);

        foreach ($players AS $i => $player) {
            // Assign 2 random pattern pairs (= 4 patterns) to the player.
            $patternIds = array_map(function($pattern) { return $pattern['pattern_id'] ;}, array_slice($patterns, $i * static::PATTERNS_PER_PLAYER, static::PATTERNS_PER_PLAYER) );
            $patternIdsString = implode(',', $patternIds);

            // Assign private objective(s) to the player.
            $privateObjectiveCount = count($players) == 1 ? 2 : 1; // Normally 1 private objective is assigned, but in a solo game 2.
            $privateObjectiveIdsString = implode(',', array_slice($randomColorChars, $i * $privateObjectiveCount, $privateObjectiveCount));

            $sql = "
                UPDATE player
                SET sag_patterns          = '{$patternIdsString}'
                  , sag_privateobjectives = '{$privateObjectiveIdsString}'
                WHERE player_no = {$player['player_no']}
            ";
            print "<PRE>" . print_r($sql, true) . "</PRE>";
            static::db($sql);
        }

        print str_repeat("<br/>", 100);
    }

    /*
        getAllDatas: 
        
        Gather all informations about current game situation (visible by the current player).
        
        The method is called each time the game interface is displayed to a player, ie:
        _ when the game starts
        _ when a player refreshes the game page (F5)
    */
    protected function getAllDatas() {
        $result = [];

        $current_player_id = self::getCurrentPlayerId();    // !! We must only return informations visible by this player !!

        // Get information about players
        // Note: you can retrieve some extra field you added for "player" table in "dbmodel.sql" if you need it.
        $sql = "SELECT player_id id, player_score score FROM player ";
        $result['players'] = self::getCollectionFromDb($sql);

        // TODO: Gather all information about current game situation (visible by player $current_player_id).



        $this->sagradaSetupNewGame();



        return $result;
    }

    /*
        getGameProgression:
        
        Compute and return the current game progression.
        The number returned must be an integer beween 0 (=the game just started) and
        100 (= the game is finished or almost finished).
    
        This method is called each time we are in a game state with the "updateGameProgression" property set to true 
        (see states.inc.php)
    */
    function getGameProgression() {
        // TODO: compute and return the game progression

        return 0;
    }


//////////////////////////////////////////////////////////////////////////////
//////////// Utility functions
////////////    

    /*
        In this space, you can put any utility methods useful for your game logic
    */


//////////////////////////////////////////////////////////////////////////////
//////////// Player actions
//////////// 

    /*
        Each time a player is doing some game action, one of the methods below is called.
        (note: each method below must match an input method in sagrada.action.php)
    */

    /*
    
    Example:

    function playCard( $card_id )
    {
        // Check that this is the player's turn and that it is a "possible action" at this game state (see states.inc.php)
        self::checkAction( 'playCard' ); 
        
        $player_id = self::getActivePlayerId();
        
        // Add your game logic to play a card there 
        ...
        
        // Notify all players about the card played
        self::notifyAllPlayers( "cardPlayed", clienttranslate( '${player_name} plays ${card_name}' ), array(
            'player_id' => $player_id,
            'player_name' => self::getActivePlayerName(),
            'card_name' => $card_name,
            'card_id' => $card_id
        ) );
          
    }
    
    */


//////////////////////////////////////////////////////////////////////////////
//////////// Game state arguments
////////////

    /*
        Here, you can create methods defined as "game state arguments" (see "args" property in states.inc.php).
        These methods function is to return some additional information that is specific to the current
        game state.
    */

    /*
    
    Example for game state "MyGameState":
    
    function argMyGameState()
    {
        // Get some values from the current game situation in database...
    
        // return values:
        return array(
            'variable1' => $value1,
            'variable2' => $value2,
            ...
        );
    }    
    */

//////////////////////////////////////////////////////////////////////////////
//////////// Game state actions
////////////

    /*
        Here, you can create methods defined as "game state actions" (see "action" property in states.inc.php).
        The action method of state X is called everytime the current game state is set to X.
    */

    /*
    
    Example for game state "MyGameState":

    function stMyGameState()
    {
        // Do some stuff ...
        
        // (very often) go to another gamestate
        $this->gamestate->nextState( 'some_gamestate_transition' );
    }    
    */

//////////////////////////////////////////////////////////////////////////////
//////////// Zombie
////////////

    /*
        zombieTurn:
        
        This method is called each time it is the turn of a player who has quit the game (= "zombie" player).
        You can do whatever you want in order to make sure the turn of this player ends appropriately
        (ex: pass).
    */

    function zombieTurn($state, $active_player) {
        $statename = $state['name'];

        if ($state['type'] === "activeplayer") {
            switch ($statename) {
                default:
                    $this->gamestate->nextState("zombiePass");
                    break;
            }

            return;
        }

        if ($state['type'] === "multipleactiveplayer") {
            // Make sure player is in a non blocking status for role turn
            $this->gamestate->setPlayerNonMultiactive($active_player, '');

            return;
        }

        throw new feException("Zombie mode not supported at this game state: " . $statename);
    }

///////////////////////////////////////////////////////////////////////////////////:
////////// DB upgrade
//////////

    /*
        upgradeTableDb:
        
        You don't have to care about this until your game has been published on BGA.
        Once your game is on BGA, this method is called everytime the system detects a game running with your old
        Database scheme.
        In this case, if you change your Database scheme, you just have to apply the needed changes in order to
        update the game database and allow the game to continue to run with your new version.
    
    */

    function upgradeTableDb($from_version) {
        // $from_version is the current version of this game database, in numerical form.
        // For example, if the game was running with a release of your game named "140430-1345",
        // $from_version is equal to 1404301345

        // Example:
//        if( $from_version <= 1404301345 )
//        {
//            // ! important ! Use DBPREFIX_<table_name> for all tables
//
//            $sql = "ALTER TABLE DBPREFIX_xxxxxxx ....";
//            self::applyDbUpgradeToAllDB( $sql );
//        }
//        if( $from_version <= 1405061421 )
//        {
//            // ! important ! Use DBPREFIX_<table_name> for all tables
//
//            $sql = "CREATE TABLE DBPREFIX_xxxxxxx ....";
//            self::applyDbUpgradeToAllDB( $sql );
//        }
//        // Please add your future database scheme changes here
//
//


    }
}
