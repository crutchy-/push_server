#!/bin/bash

# add "*/1 * * * * bash /var/include/vhosts/default/inc/push_server/cron_ws_restart.sh" to end of crontab

sudo systemctl start push_server.service
# requires following line in visudo:
# <username> ALL=NOPASSWD: /bin/systemctl start push_server.service

exit 0
