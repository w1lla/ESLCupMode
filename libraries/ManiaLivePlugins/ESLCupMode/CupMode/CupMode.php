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

        $this->enableStorageEvents();
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
		
		//var_dump($this->out_ptslimit);
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
	
	
	// Invoke a ForceScores call for a specific player login
	public function setPlayerScore( $login, $score ) {
		global $_players;
		$this->connection->forceScores(( Array( "PlayerId" => $_players[$login]['PlayerId'], "Score" => $score ) ), true);
	}
	
	public function onPlayerFinish($playerUid, $login, $timeOrScore) {
		
		if( $timeOrScore <= 0 )
			return;
		
		Console::println('[' . date('H:i:s') . '] Player '.$login.' finished with time '.$timeOrScore.'.');
		$this->finishers[$timeOrScore] = $login;
		
	}
	
	public function onBeginRound(){
	
	Console::println('[' . date('H:i:s') . '] onBeginRound ');
		
		// Recalculate ptslimit (as this cb also gets called after a mapchange) and check for next map bug
		$scores = $this->playersGetScore();
		$max_pl = 0;
		$bug_winner = false;
		foreach( $scores as $login => &$pl ) {
			if( $login == @$this->winner['Login'] ) {
				// Check for next map bug
				$score = $pl['Score'];
				if( $score < 0 )
					Console::println('[' . date('H:i:s') . '] Last winner disconnected.');
				else if( $score > 0 ) {
					if( !($score > $this->limit['CurrentValue'] * 2) ) {
						Console::println('[' . date('H:i:s') . '] Last winner is no more a winner and has a score >0. Fixing bug.');
						$this->setPlayerScore( $this->winner['Login'], $this->winner['Score'] );
						Console::println('[' . date('H:i:s') . '] Set score to  '. $this->winner['Score'] . ' and make player a spectator.');
						$this->connection->forceSpectator($this->winner['Login'], 1 );
						$this->connection->forceSpectator($this->winner['Login'], 0 );
						$bug_winner = true;
					} else
						Console::println('[' . date('H:i:s') . '] Last winner is still a winner.');
				} else
					Console::println('[' . date('H:i:s') . '] Last winner has score 0, so race was restarted.');
				$this->winner['Login'] = "";
			} else {
				// Calculate pointslimit
				if( $pl['Score'] <= $this->limit*2 and $pl['Score'] > $max_pl )
					$max_pl = $pl['Score'];
			}
		}
		$this->out_ptslimit = max( $max_pl, $this->limit );
		Console::println('[' . date('H:i:s') . '] New pointslimit: '.$this->out_ptslimit.' (max_pl = '.$max_pl.').');
		
		// Count winners (in case of endround/nextmap bug, the endround cb can't count it himself)
		$this->winners = 0;
		foreach( $scores as $login => &$pl ) {
			if( $pl['Score'] > $this->limit*2 )
				$this->winners++;
		}
		if( $bug_winner )
			$this->winners++;
		Console::println('[' . date('H:i:s') . '] Counted number of winners is '.$this->winners.'.' );
		
		/*// Fix round end bug
		if( $this->roundendbug ) {
			$this->if->log( "Fixing round end bug." );
			$scores = $this->if->playersGetScore();
			$rpoints = $this->if->getRoundCustomPoints();
			$update = Array();
			foreach( $scores as $login => &$pl ) {
				if( $pl['Score'] - $rpoints[0] >= $this->out_ptslimit ) {
					$this->winners++;
					$update[] = Array( "PlayerId" => $pl['PlayerId'], "Score" => $this->limit*2 + $this->if->getCupNbWinners() - $this->winners + 1 );
					$this->if->log( "Player {$login} is new winner! Points set to " . $update[0]['Score'] . ", number of winners is {$this->winners} and CupNbWinners is " . $this->if->getCupNbWinners() . "." );
				}
				// Update winners score
				if( count($update) > 0 )
					$this->if->serverCall( "ForceScores", $update, true );
			}
			$this->roundendbug = false;
		}*/
		
		// Reset finishers/finalists array on round start
		unset( $this->finishers ); $this->finishers = array();
		unset( $this->finalists ); $this->finalists = array();
		
		// Build finalists array
		$scores = $this->if->playersGetScore( true );
		foreach( $scores as $login => &$pl ) {
			if( $pl['Score'] == $this->out_ptslimit ) {
				$this->finalists[] = $login;
				Console::println('[' . date('H:i:s') . '] '.$login .' (score = '. $pl['Score'] . ') is a finalist!' );
			} else {
				Console::println('[' . date('H:i:s') . '] '.$login.' (score = ' . $pl['Score'] . ') is no finalist.' );
			}
		}
		
		// Update manialink
		//if( $this->state )
		//	$this->mlUpdateXml();
	
	}
	
	public function onEndRound() {
	
		// Return when disabled
		if( !$this->state ) {
			unset( $this->finishers ); $this->finishers = array();
			return;
		}
		
		// Return when noone finished
		if( count($this->finishers) < 1 )
			return;
		
		// Fetch first finisher
		ksort( $this->finishers );
		foreach( $this->finishers as $time => $login ) {
			// foreach is only used to determine first entry
			break;
		}
		Console::println('[' . date('H:i:s') . '] onEndRound ');
		Console::println('[' . date('H:i:s') . '] Player '.$login.' with time '.$time.' finished first.' );
		//$this->if->log( "- out_ptslimit = " . $this->out_ptslimit );
		//$this->if->log( "- limit = " . $this->limit );
		//$this->if->log( "- winners (before) = " . $this->winners );
		
		// Prepare winner check
		$scores = $this->playersGetScore();
		$rpoints = $this->connection->getRoundCustomPoints();
		$update = Array();
		$new_winner = "";
		/*// Check for round end zero bug
		$this->if->log( "Cecking for round end bug, Score = " . $scores[$login]['Score'] );
		if( $scores[$login]['Score'] < 1 ) {
			$this->if->log( "Detected. Trying to fetch newest ranking." );
			$scores = $this->if->playersGetScore( true );	
		}
		if( $scores[$login]['Score'] < 1 ) {
			$this->if->log( "Not solved." );
			$this->if->chat( "FUUUUUUCKING ROUND END BUG DETECTED!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!" );
			$this->roundendbug = true;
		}
		if( ($scores[$login]['Score'] - $rpoints[0]) >= $this->out_ptslimit ) {
			// As were doing this at endround, we have to check if he really was finalist before
			$this->winners++;
			$update[] = Array( "PlayerId" => $scores[$login]['PlayerId'], "Score" => $this->limit*2 + $this->if->getCupNbWinners() - $this->winners + 1 );
			$new_winner = $login;
			$this->if->log( "Player {$login} is new winner! Points set to " . $update[0]['Score'] . ", number of winners is {$this->winners} and CupNbWinners is " . $this->if->getCupNbWinners() . "." );
		}*/
		// See if first finisher is a finalist (->winner)
		if( in_array( $login, $this->finalists ) ) {
			$new_winner = $login;
			// Update player score
			$update[] = Array( "PlayerId" => $scores[$new_winner]['PlayerId'], "Score" => $this->limit*2 + $this->if->getCupNbWinners() - $this->winners + 1 );
			$this->winner = array( "Login" => $new_winner, "Score" => $update[0]['Score'] );
			Console::println('[' . date('H:i:s') . '] Player '.$login.' is a winner. Setting score to '. $update[0]['Score'] . '.' );
		} else {
			Console::println('[' . date('H:i:s') . '] No luck, '.$login.' is not a winner :/' );
		}
		// Update winners score
		if( count($update) > 0 )
			$this->connection->forceScores($update, true);
		
		//$this->if->log( "- score = " . $scores[$login]['Score'] );
		//$this->if->log( "- count(update) = " . count($update) );
		
		// Recalculate pointslimit
		$update = Array(); $max_pl = 0;
		foreach( $scores as $login => &$pl ) {
			// Search max player score
			if( $pl['Score'] > $max_pl && $pl['Score'] <= 2*$this->limit ) {
				// This player has current max score and is not a winner, check if it's the new winner
				if( $login != $new_winner )
					$max_pl = $pl['Score'];
			}
		}
		$this->out_ptslimit = max( $max_pl, $this->limit );
		Console::println('[' . date('H:i:s') . '] New out_ptslimit = ' . $this->out_ptslimit . '; max_pl = ' . $max_pl.'');
		
		// Empty finishers array
		unset($this->finishers); $this->finishers = array();
		
		// Update manialink markup
		//$this->mlUpdateXml();
		
	}
	
}
?>