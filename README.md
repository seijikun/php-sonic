# php-sonic
Synchronous PHP client library for the sonic search backend.
The client is as simple as possible, in only a single file.

## API
Sonic requires one to choose the type/mode of a session per connection, with no possibility of changing the selected mode later on.
The API thus looks as follows:

```
 AbstractSonicSessionBase
 |
 |- SonicSearchSession
 |
 |- SonicIngestSession
 |
 |- SonicControlSession
```

When connecting to Sonic, one chooses between the three classes `SonicSearchSession`, `SonicIngestSession`, `SonicControlSession`, of which each resembles one possible mode of operation for Sonic:

Have a look at the examples folder!
