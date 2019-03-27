<?php

include "../src/SonicClient.php";
define('__SONIC_CLIENT_DEBUG__', true);

$client = new SonicIngestSession('localhost', 1491, 'SecretPassword');
try {
	$client->connect();
} catch(SonicUnreachableException $e) {
	echo "Failed to connect to Sonic:";
	var_dump($e);
	exit;
}

// Ping sonic (to keep the session alive, e.g.)
$client->ping();

/* Ask sonic to clear the messages index */
$client->flush('messages');
// To delete more specific paths:
$client->flush('messages', 'default');
$client->flush('messages', 'default', 'conversation:x1337x');

/* Let's imagine having a chat conversation and push some messages into Sonic's index */
$client->push('messages', 'default', 'conversation:x1337x', 'Sonic sounds interesting. Lets see how it performs as backend for nextcluods fulltextsearch framework!');
$client->push('messages', 'default', 'conversation:x1337x', 'Haha nice idea! Elasticsearch is damned slow!');

/* Let's ask Sonic to count some stuff for us */
echo "Amount of collections: " . $client->count('messages');
echo "Amount of conversations: " . $client->count('messages', 'default');
echo "Amount of Terms in conversation: " . $client->count('messages', 'default', 'conversation:x1337x');

?>
