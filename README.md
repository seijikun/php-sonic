# php-sonic
Synchronous PHP client library for the sonic search backend.
The client is as simple as possible, in only a single file.

## API
Sonic requires one to choose the type/mode of a session per connection, with no possibility of changing the selected mode later on. This results in the following API-Design:
When connecting to Sonic, one chooses between the three classes `SearchSession`, `IngestSession`, `ControlSession`, of which each resembles one possible mode of operation for Sonic:

### Ingest
```php
$client = new \SonicSearch\IngestSession('localhost', 1491, 'SecretPassword');
try {
        $client->connect();
} catch(\SonicSearch\SonicUnreachableException $e) {
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
```

### Search
```php
$client = new \SonicSearch\SearchSession('localhost', 1491, 'SecretPassword');
try {
        $client->connect();
} catch(\SonicSearch\SonicUnreachableException $e) {
        echo "Failed to connect to Sonic:";
        var_dump($e);
        exit;
}

// Ping sonic (to keep the session alive, e.g.)
$client->ping();

// Query Sonic
$results = $client->query('messages', 'default', 'Elasticsearch slow');
var_dump($results);

// Let Sonic suggest auto-completion for some words
var_dump($client->suggest('messages', 'default', 'So', 1));
```

### Control

```php
$client = new \SonicSearch\SearchSession('localhost', 1491, 'SecretPassword');
try {
        $client->connect();
} catch(\SonicSearch\SonicUnreachableException $e) {
        echo "Failed to connect to Sonic:";
        var_dump($e);
        exit;
}

// Ping sonic (to keep the session alive, e.g.)
$client->ping();

// Query Sonic
$results = $client->query('messages', 'default', 'Elasticsearch slow');
var_dump($results);

// Let Sonic suggest auto-completion for some words
var_dump($client->suggest('messages', 'default', 'So', 1));
```
