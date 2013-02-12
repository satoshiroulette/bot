<?php
# *****************************************************************************
# Satoshi Roulette Bot
# *****************************************************************************
#
# references:
# https://bitcointalk.org/index.php?topic=137519.msg1512149#msg1512149
# http://blockchain.info/api/blockchain_wallet_api
# http://patorjk.com/software/taag/#p=display&f=Big%20Money-se&t=W%20I%20N

# *****************************************************************************
# Includes

include './jsonRPCClient.php';

# *****************************************************************************
# Variables
# *****************************************************************************

global $address;
global $min;
global $max;
global $max_old;
global $jackpot;
global $sleep;
global $sleep_max;
global $user;
global $password;
global $coind;

# -----------------------------------------------------------------------------
# Begin user defined settings
# -----------------------------------------------------------------------------

# bot settings
$blockchain		= 1;			# 1 for using blockchain.info wallets, set mode to BTC
$mode			= 'BTC';		# mode: BTC/ LTC/ PPC
$game_name		= 'jdice-066';	# name of the game you are playing
$x				= 2.1;			# when a bet loses, the next bet = $bet * $x;
$min			= 0.1;			# if lower than the games min, the games min is used
$max			= 0;			# if higher than the games max, the games max is used
$sleep			= 20;			# sleep time: 20 seconds seems optimal
$sleep_max		= 600;			# max random sleep time, set to the same as $sleep to disable random sleep
$goto_max		= 1;			# if the next bet greater than the games max bet, bet max. Used for collecting jackpots.

# bitcoind/ litecoind/ trcoind rpc connection settings

$ip				= '127.0.0.1';	# coind ip
$user			= 'user';		# coind rpc user
$password		= 'password';	# coind rpc password
$port			= 9333;			# coind rpc port

$json_coind_url	= "http://$user:$password@$ip:$port/";

# -----------------------------------------------------------------------------
# End user defined settings
# -----------------------------------------------------------------------------

$blockchain		= 0;
$balance		= 0;
$jackpot		= 0;

# -----------------------------------------------------------------------------

$json_coind_url	= "http://$user:$password@$ip:$port/";
$address		= '';
$win_string =
'
 __       __        ______        __    __ 
|  \  _  |  \      |      \      |  \  |  \
| $$ / \ | $$       \$$$$$$      | $$\ | $$
| $$/  $\| $$        | $$        | $$$\| $$
| $$  $$$\ $$        | $$        | $$$$\ $$
| $$ $$\$$\$$        | $$        | $$\$$ $$
| $$$$  \$$$$       _| $$_       | $$ \$$$$
| $$$    \$$$      |   $$ \      | $$  \$$$
 \$$      \$$       \$$$$$$       \$$   \$$
 
 ';

# *****************************************************************************
# Start
# *****************************************************************************

load_game($mode, $game_name);
$bet = $min;

if(!$blockchain) # connect to coind via rpc if not using blockchain.info api
{
	$coind = new jsonRPCClient($json_coind_url);
}

while(1)
{
	$balance	= check_balance();
	$bet_txid	= send_bet($bet, $address);	
	echo "Balance: $balance $mode, $game_name amount: $bet $mode, txid = $bet_txid\n";
	$result		= get_result($mode, $bet_txid, $game_name);
	
	# -----------------------------------------------------------------------------
	# check win / lose
	# -----------------------------------------------------------------------------

	if($result)	# WIN
	{
		echo $win_string;
		$bet = $min;
	}
	else		# LOSE
	{
		load_game($mode, $game_name);	# reload game, check for max bet increase / decrease.
		echo "\nlost, increasing bet size and rebetting\n";
		$bet = 0 + sprintf("%.8f", $bet * $x);
		if($bet > $max)
		{
			echo "bet greater than max, starting from min again\n";
			$bet = $min;
		}
		else if($goto_max && $bet * $x > $max)
		{
			echo "going to MAX !\n";
			$bet = $max;
		}		
	}
}

# *****************************************************************************
# done
# *****************************************************************************
exit;

# -----------------------------------------------------------------------------
# Load Game
# -----------------------------------------------------------------------------

function load_game($mode, $game_name)
{
	$jsonurl			= "http://satoshiroulette.com/api.php?game=$game_name&mode=$mode";
	$json				= file_get_contents($jsonurl);
	$game				= json_decode($json);
	$GLOBALS['address']	= $game->{'address'} ;

	# jackpot
	if(isset($game->{'jackpot'}))
	{
		$GLOBALS['jackpot'] = $game->{'jackpot'};
	}
	
	$GLOBALS['jackpot'] = sprintf("%.8f", $GLOBALS['jackpot']);

	# make sure min and max bets are within games range.
	if($GLOBALS['min'] < $game->{'min_bet'})
	{
		$GLOBALS['min'] = $game->{'min_bet'} ;
	}
	else if($GLOBALS['min'] > $game->{'max_bet'})
	{
		$GLOBALS['$min'] = $game->{'min_bet'} ;
	}
	if($GLOBALS['max'] > $game->{'max_bet'} || !$GLOBALS['max'])
	{
		$GLOBALS['max'] = $game->{'max_bet'};
	}

	# if max bet has changed, print new game info
	if($GLOBALS['max_old'] != $GLOBALS['max'])
	{
		$GLOBALS['max_old'] = $GLOBALS['max'];
		echo "Game: $game_name\nAddress: ".$GLOBALS['address']."\nMin Bet: ".$GLOBALS['min']."\nMax Bet: ".$GLOBALS['max']."\nJackpot: ".$GLOBALS['jackpot']."\n \n";
	}
}

# -----------------------------------------------------------------------------
# Get Balance
# -----------------------------------------------------------------------------

function check_balance()
{
	if($GLOBALS['blockchain'])
	{
		$jsonurl = "https://blockchain.info/merchant/".$GLOBALS['user']."/balance?password=".$GLOBALS['password'];
		$json = file_get_contents($jsonurl,0,null,null);
		$json = json_decode($json);
		$balance = $json->{'balance'} / 100000000;
	}
	else
	{
		$balance = $GLOBALS['coind']->getbalance('*', 0);
	}	
	return $balance;
}

# -----------------------------------------------------------------------------
# wait for bet result
# -----------------------------------------------------------------------------

function get_result($mode, $bet_txid, $game_name)
{
	unset($r);
	$jsonurl = "http://satoshiroulette.com/log.api.php?txid=$bet_txid&mode=$mode";

	while(! isset($r->{$game_name}) )
	{
		$json = file_get_contents($jsonurl);
		$r = json_decode($json);
		if(isset($r->{$game_name}))
		{
			$result = $r->{$game_name};
		}
		print ".";
		$s = rand($GLOBALS['sleep'], $GLOBALS['sleep_max']);
		sleep($s);
	}
	return $result;
}

# -----------------------------------------------------------------------------
# Bet
# -----------------------------------------------------------------------------

function send_bet($bet, $address)
{
	if($GLOBALS['blockchain'])
	{
		$b = $bet * 100000000;
		$jsonurl = "https://blockchain.info/merchant/".$GLOBALS['user']."/payment?password=".$GLOBALS['password']."&to=$address&amount=$b";
		$json = file_get_contents($jsonurl,0,null,null);
		$bet_tx = json_decode($json);
		$bet_txid = $bet_tx->{'tx_hash'};
	}
	else
	{
		#print "address = $address, bet = $bet\n";
		$bet_txid = $GLOBALS['coind']->sendtoaddress($address, (float) $bet );
	}
	return $bet_txid;
}
?>