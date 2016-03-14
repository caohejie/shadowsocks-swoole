<?php

include 'lib/autoload.php';

// 状态相关
define('STAGE_INIT', 0);
define('STAGE_ADDR', 1);
define('STAGE_UDP_ASSOC', 2);
define('STAGE_DNS', 3);
define('STAGE_CONNECTING', 4);
define('STAGE_STREAM', 5);
define('STAGE_DESTROYED', -1);

// 命令
define('CMD_CONNECT', 1);
define('CMD_BIND', 2);
define('CMD_UDP_ASSOCIATE', 3);

class ProxyServer
{
    protected $clients;
    protected $backends;
    protected $serv;
    protected $stage;
    protected $buff;
    protected $crypt;

    function run()
    {

        $config      = $this->getConfig();
        $this->crypt = new Encryptor($config['password'], $config['method']);

        $ser = new swoole_server("127.0.0.1", 1088);
        $ser->set(array(
            'timeout'         => 1, //select and epoll_wait timeout.
            'poll_thread_num' => 10, //reactor thread num
            'worker_num'      => 88, //reactor thread num
            'backlog'         => 128, //listen backlog
            'max_conn'        => 10000,
            'dispatch_mode'   => 2,
            //'open_tcp_keepalive' => 1,
            'log_file'        => '/tmp/swoole.log', //swoole error log
        ));
        $ser->on('workerstart', array($this, 'onStart'));
        $ser->on('connect', array($this, 'onConnect'));
        $ser->on('receive', array($this, 'onReceive'));
        $ser->on('close', array($this, 'onClose'));
        $ser->on('workerstop', array($this, 'onShutdown'));
        $ser->start();
    }

    function getConfig()
    {
        $config['port']       = '8989';
        $config['server']     = '127.0.0.1';
        $config['method']     = 'aes-256-cfb';
        $config['password']   = '12345678';
        $config['local_port'] = '1088';
        return $config;
    }

    function onStart($serv)
    {
        $this->serv = $serv;
        echo "[Server]: start.Swoole version is [" . SWOOLE_VERSION . "]\n";
    }

    function onShutdown($serv)
    {
        echo "[Server]: onShutdown\n";
    }

    function onClose($serv, $fd, $from_id)
    {
        //backend
        if (isset($this->clients[$fd])) {
            $backend_client = $this->clients[$fd]['socket'];
            unset($this->clients[$fd]);
            $backend_client->close();
            unset($this->backends[$backend_client->sock]);
            echo "client close\n";
        }
    }

    function onConnect($serv, $fd, $from_id)
    {
        echo str_repeat('-', 20) . "Browser[$fd]----connnect---->Server" . str_repeat('-', 20) . "\n";
        $this->clients[$fd] = array(
            'stage' => STAGE_INIT,
        );

    }

    function connectRemote($fd)
    {
        $socket = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);

        $this->clients[$fd] = array(
            'socket' => &$socket
        );
        $socket->on('connect', function ($socket) use ($fd) {
            $this->backends[$socket->sock] = array(
                'client_fd' => $fd,
                'socket'    => $socket,
            );
            echo str_repeat('-', 20) . "Client[$socket->sock]----connnect---->Backend" . str_repeat('-', 20) . "\n";
        });
        $socket->on('error', function ($socket) {
            echo "connect to backend server fail\n";
        });
        $socket->on('receive', function ($socket, $data) {
            echo str_repeat('-', 20) . "Client[$socket->sock]-----recv------>Backend" . str_repeat('-', 20) . "\n";
            //echo "receive len " . strlen($data);
            //echo PHP_EOL;
            $data = $this->crypt->decrypt($data);
            $fd   = $this->backends[$socket->sock]['client_fd'];
            //echo PHP_EOL;
            //$this->buff[$fd] = empty($this->buff[$fd]) ? $data : $this->buff[$fd] . $data;
            $this->serv->send($fd, $data);

            //var_dump($this->buff[$fd]);
            echo str_repeat('-', 20) . "Client[$socket->sock]-----recv------>Backend" . str_repeat('-', 20) . "\n";
        });

        $socket->on("error", function ($socket) {
            exit("error\n");
        });

        $socket->on("close", function ($socket) {
            $fd = $this->backends[$socket->sock]['client_fd'];
            //if (!empty($this->buff[$fd])) {
            //    $this->serv->send($fd, $this->buff[$fd]);
            //}
            echo "$fd connection is closed\n";
        });
        $config = $this->getConfig();
        $socket->connect($config['server'], $config['port'], 0.5);
    }

    function onReceive($serv, $fd, $from_id, $data)
    {

        echo str_repeat('-', 20) . "broswer[$fd]----str---->server" . str_repeat('-', 20) . "\n";
        $stage = $this->clients[$fd]['stage'];
        //echo "[Local] Current stage->$stage|fd->$fd \n";
        switch ($stage) {
            case STAGE_INIT:
                $this->connectRemote($fd);
                $this->clients[$fd]['stage'] = STAGE_ADDR;
                $serv->send($fd, "\x05\x00");
                break;
            case STAGE_ADDR:
                $cmd = ord($data[1]);
                //仅处理客户端的TCP连接请求
                if ($cmd != CMD_CONNECT) {
                    echo "unsupport cmd\n";
                    $serv->send($fd, "\x05\x07\x00\x01");
                    return $serv->close();
                }

                //echo "[Server] send Address to backend\n";
                //echo str_repeat('-', 100) . "\n";
                $buf_replies = "\x05\x00\x00\x01\x00\x00\x00\x00" . pack('n', 1088);
                $serv->send($fd, $buf_replies);
                $this->clients[$fd]['stage'] = STAGE_CONNECTING;
                $buffer                      = substr($data, 3);
                $data                        = $this->crypt->encrypt($buffer);
                $backend_socket              = $this->clients[$fd]['socket'];
                if ($backend_socket->isConnected()) {
                    $backend_socket->send($data);
                }

                break;
            default:
                //echo time() . ": client receive\n";
                //$data           = substr($data, 3);
                //echo "[Server] current rec data len is " . strlen($data);
                echo PHP_EOL;
                $data = $this->crypt->encrypt($data);
                //var_dump($data);
                //echo "[Server] current rec data len is " . strlen($data);
                $backend_socket = $this->clients[$fd]['socket'];
                if ($backend_socket->isConnected()) {
                    $backend_socket->send($data);
                }
                //echo "[Server] send data to backend\n";
                break;
        }
        echo str_repeat('-', 20) . "broswer[$fd]----end---->server" . str_repeat('-', 20) . "\n";
    }
}

$inst = new ProxyServer();
$inst->run();