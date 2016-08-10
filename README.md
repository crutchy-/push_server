# push_server

simple php websockets server

features/limitations:
- one file (server.php)
- supports RFC6455 (version 13) only
- supports text data frames (doesn't support binary data frames)
- supports close, ping (receive only) and pong (send only) control frames
- supports receiving of fragmented frames
- doesn't support extensions
- configuration options are at the top of the script
- no dependencies other than php (tested with version 5.6)
- uses procedural programming style
- no framework cruft
