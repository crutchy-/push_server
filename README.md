# push_server

simple php websockets server

features/limitations:
- one file (server.php)
- supports RFC6455 (version 13) only
- supports text data frames (doesn't support binary data frames)
- supports close, ping (receive only) and pong (send only) control frames
- supports fragmented frames
- doesn't support extensions
- configuration options are at the top of the script
- no dependencies other than php (tested with version 5.6)
- uses procedural programming style
- no framework cruft


# autobahn/testsuite

used to test this server

refer to http://autobahn.ws/testsuite

## installing on debian jessie

as root user:
````
apt-get install python-setuptools
apt-get install python-dev libxml2-dev libxslt-dev
apt-get install python-twisted
apt-get install python-pip
pip install cffi
pip install traceback2
pip install attrs
pip install autobahntestsuite
````

## using

set listening port of server to 9001

as non-root user:
````
wstest -fuzzingngclient
````
