<?php
/**
Name: Willem 'W1lla' van den Munckhof
Date: 2013-06-08
Project Name: ESL CupMode original by Svens

**/
/**
 * ---------------------------------------------------------------------
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 * You are allowed to change things or use this in other projects, as
 * long as you leave the information at the top (name, date, version,
 * website, package, author, copyright) and publish the code under
 * the GNU General Public License version 3.
 * ---------------------------------------------------------------------
 */

 namespace ManiaLivePlugins\ESLCupMode\CupMode;

use ManiaLive\Data\Storage;
use ManiaLive\Utilities\Console;
use ManiaLive\Features\Admin\AdminGroup;

class CupMode extends \ManiaLive\PluginHandler\Plugin {


	public $relay_support = false;	// Set to true if relay mode is available
	
	public $state = false;	// Denotes if eslcup is currently "running" or not
	public $relay = false;	// Denotes if this is a relay of a eslcup server (shows manialink)
	public $finishers = array();	// Array of finishers
	public $finalists = array();	// Array of finalists
	public $winner = array();	// Login and score of last winner
	public $winners = 0;	// Number of winners
	public $limit = 120;	// Pointslimit
	public $roundendbug = false;	// Denotes if roundendbug has to be fixed

	// Output for display
	public $out_manialink = "";	// Standard manialink
	public $out_ptslimit = 0;	// Current pointslimit
	public $out_widget = "";	// Second manialink with cup scores
	public $out_widget_to = 0;	// Display widget only to spectators (2), players would be (1) and both (0)
	public $out_widget_n = 4;	// Number of players to display
	// Manialink templates at the bottom of the file
	
	function onInit() {
		$this->setVersion('0.05rc2');
	}

	function onLoad() {

		$this->enableDatabase();
		$this->enableDedicatedEvents();
		
		$admins = AdminGroup::get();
		
		$cmd = $this->registerChatCommand('eslcup', 'eslcup', 2, true, $admins);
		$cmd->help = 'ESL Cupmode.';
		
		$cmd = $this->registerChatCommand('eslcup', 'eslcup', 1, true, $admins);
		$cmd->help = 'ESL Cupmode.';
		
		Console::println('[' . date('H:i:s') . '] [ESLCupMode] ESL CupMode v' . $this->getVersion());
		$this->connection->chatSendServerMessage('eslcup: rev. ' . $this->getVersion() . ' loaded');
		
	}
	
	public function eslcup($login, $arg, $param = null) {
        switch ($arg) {
            case "activate":
                $this->activate($login);
                break;
            case "disable":
                $this->disable($login);
                break;
            case "restore":
                $this->restore($login);
                break;
			case "limit":
			$this->connection->setCupPointsLimit(intval($param));
			$this->connection->forceEndRound();
			$this->connection->chatSendServerMessage('Set PointLimit to value: '.$param.'', $login);
			break;
            default:
                $this->connection->chatSendServerMessage('Usage.. /eslcup activate, /eslcup limit x, /eslcup disable"', $login);
                break;
        }
    }
	
	// Activate the plugin
	
	public function activate( $login ) {
		
		if( $this->state) {
			$this->connection->chatSendServerMessage('ESL CupMode Already activated.', $login);
			return;
		} else {
			// Read out server settings
			$this->limit = $this->connection->getCupPointsLimit();
			//var_dump($this->limit);
			$this->state = true;
			// Make necessary calls
			$this->changeLimit($this->limit);
			// Update manialink xml
			//$this->out_ptslimit = $this->limit;
			//$this->mlUpdateXml();
		}
		$this->connection->chatSendServerMessage('ESL CupMode Activated.', $login);
		return;
		
	}
	
	public function disable( $login ) {
		
		if( $this->state ) {
			// Restore server settings
			$old = $this->limit['NextValue'] / 2;
			$this->connection->setCupPointsLimit($old);
			$this->connection->forceEndRound();
			$this->state = false;
		} else {
			$this->connection->chatSendServerMessage('ESL CupMode Already disabled.', $login);
			return;
		}
		$this->connection->chatSendServerMessage('ESL CupMode disabled.', $login);
		return;
		
	}
	
	public function restore($login) {
		
		if( $this->state ) {
			$this->connection->chatSendServerMessage('ESL CupMode Already activated.', $login);
		}
		
		$this->limit = $this->connection->getCupPointsLimit();
		$type = $this->limit['CurrentValue'] / 2;
		$this->state = true;
		
		$scores = $this->playersGetScore();
		$max_pl = 0;
		foreach( $scores as $login => &$pl ) {
		//var_dump($pl);
			// Search max player score
			if( $pl['Score'] > $max_pl && $pl['Score'] <= 2 * $type )
				$max_pl = $pl['Score'];
		}
		$this->out_ptslimit = max( $max_pl, $this->limit );
		//$this->mlUpdateXml();
		
		// Search for finalists
		foreach( $scores as $login => &$pl ) {
			if( $pl['Score'] == $this->out_ptslimit )
				$finalists[] = $login;
		}
		
		var_dump($this->out_ptslimit);
		Console::println('Restoring eslcup state. Variable dump:');
		Console::println('(limit - out_ptslimit) = ('.$this->limit['CurrentValue'].' - '.$this->out_ptslimit['CurrentValue'].')' );
		$this->connection->chatSendServerMessage('ESL CupMode Reactivated.', $login);
		
	}
	
	public function changeLimit($v) {
		// Change server settings
		$endround = false;
		if( $this->state ) {
			$type = $v['CurrentValue'] * 2;
			$this->connection->setCupPointsLimit($type);
			$this->winnerScore($this->limit, $type);
			if( $this->connection->getCupPointsLimit() != $v['CurrentValue'] * 2 )
				$endround = true;
		} else {
			$this->connection->setCupPointsLimit($type);
			$this->winnerScore( $this->limit, $type);
		}
		$this->limit = $v;
		//$this->mlUpdateXml();
		if($endround){
		$this->connection->forceEndRound();
		Console::println('[' . date('H:i:s') . '] changeLimit('.$type.') - ForceEndRound: ' . ($endround? "yes":"no").'');
		$this->connection->chatSendServerMessage('Successfully changed pointslimit to '.$type.'. A ForceEndRound has ' . ($endround? "not":"") . ' been invoked.');
		}
		
	}
	
	public function winnerScore( $old, $new ) {
		
		$scores = $this->playersGetScore();
		$update = array();
		foreach( $scores as $login => &$pl ) {
			if( $pl['Score'] > $old['CurrentValue'] )
				$update[] = array( "PlayerId" => $pl['PlayerId'], "Score" => $new+1 );
		}
		$this->connection->forceScores($update);
		
		Console::println('[' . date('H:i:s') . '] Updating winner scores. Pointslimit risen from '.$old['CurrentValue'].' to '.$new.'.');
		
	}
	
	public function playersGetScore($query = false) {
		/*global $_players;
		return $_players;*/
		// Uses $_Ranking to get most accurate scores
		global $_Ranking;
		$out = Array();
			$ranking = $this->connection->getCurrentRanking(256, 0);
		foreach( $ranking as &$r ) {
			$out[ $r->login ] = array( "Score" => $r->score, "NbrLapsFinished" => $r->nbrLapsFinished, "Rank" => $r->rank, "PlayerId" => $r->playerId );
		}
		return $out;

	}
}
?>