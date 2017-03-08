#!/bin/bash
# :set fileformat=unix

function start()
{
    pidNum=`ps -ef | grep /server/$1 | grep -v "grep" | wc -l`
    if [ $pidNum -gt 0 ]; then
        echo "The process is  already running, please stop it first";
        return 0;
    else
        nohup /usr/local/php/bin/php /server/$1 1>/dev/null &
        sleep 1;
        pidNum2=`ps -ef | grep /server/$1 | grep -v "grep" | wc -l`
        if [ $pidNum2 -gt 0 ]; then
            echo "The process is run successful";
            return 1;
        else
            echo "The process is run failed";
            return 0;
        fi 
    fi
}

function stop()
{
    pidNum=`ps -ef | grep /server/$1 | grep -v "grep" | wc -l`;
    if [ $pidNum -gt 0 ]; then
        echo "stopping...";
        pid1=`ps -ef | grep /server/$1 | grep -v "grep" | awk '{print $2}' | xargs`;
        kill -9 $pid1;
        sleep 1;
        pid2=`netstat -apn | grep $2 | awk '{print $7}'`;
        pid2=${pid2%/*};
        if [ $pid2 ]; then
            kill -9 $pid2;
        fi
        sleep 1
        echo "The process is stop.";
        return 1;
    else
        echo "No running process.";
        return 0;
    fi
}

function restart()
{
    stop $1 $2;
    state=$?;
    if [ $state -eq 0 ]; then
        exit;
    fi
    start $1;
}

function info()
{
    echo "process:";
    ps -ef | grep /server/$1 | grep -v "grep"
    echo "";
    echo "port:";
    netstat -apn | grep $2;
    echo "";
}

if [ "$1"x == "ActiveServer.php"x ]; then
    port="9501";
elif [ "$1"x == "JfqClick.php"x ]; then
    port="9551";
elif [ "$1"x == "JfqActivate.php"x ]; then
    port="9502";
elif [ "$1"x == "GdtClick.php"x ]; then
    port="9552";
elif [ "$1"x == "GdtActivate.php"x ]; then
    port="9503";
elif [ "$1"x == "CheckClick.php"x ]; then
    port="9550";
elif [ "$1"x == "-h"x ]; then
    echo "format: sh service.sh xxx.php start|stop|restart|info";
    echo "";
    exit;
else
    echo "The first parameter is unknown.";
    echo "";
    exit;
fi

if [ "$2"x == "start"x ]; then
    start $1;
elif [ "$2"x == "stop"x ]; then
    stop $1 $port;
elif [ "$2"x == "restart"x ]; then
    restart $1 $port;
elif [ "$2"x == "info"x ]; then
    info $1 $port;
elif [ "$2"x == "-h"x ]; then
    echo "format: sh service.sh xxx.php start|stop|restart|info";
    echo "";
else
    echo "The second parameter is unknown.";
    echo "";  
fi
exit;
