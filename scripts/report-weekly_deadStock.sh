#!/usr/bin/env bash
# adjust path accordingly.
WMS_PATH="/var/www/gbj/wms"
try=0
until [ ${try} -ge 5 ]
do
    /usr/bin/php ${WMS_PATH}/api/report/RefreshAndSendWeeklyDeadStockReport.php && break
    try=$[${try}+1]
    sleep 15
done
