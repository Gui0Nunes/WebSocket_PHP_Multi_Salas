<?php
//192.168.15.106
//$host = "127.0.0.1";
 $host = "0.0.0.0";
$port = 8080;
$serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($serverSocket, $host, $port);
socket_listen($serverSocket);

$clients = [$serverSocket]; 
$rooms = [];

echo "Servidor WebSocket rodando em ws://$host:$port\n";

while (true) {
    $changed = $clients;
    $null = NULL; // Adiciona uma variável nula para evitar erros
    if (empty($changed)) {
        continue; // Evita o erro se não houver clientes conectados ainda
    }    
    
    socket_select($changed, $null, $null, 0, 10);

    if (in_array($serverSocket, $changed)) {
        echo "📡 Tentando aceitar uma nova conexão...\n"; // Log antes
    
        $clientSocket = socket_accept($serverSocket);
        
        if ($clientSocket === false) {
            echo "❌ Erro: `socket_accept()` falhou.\n";
        } else {
            echo "🔵 Novo cliente tentando conectar...\n"; // Se aceitar, mostra conexão
        }
    
        $clients[] = $clientSocket;
        
        // 🔹 Adicionando a verificação do socket_read()
        $header = socket_read($clientSocket, 1024);
        if ($header === false || strlen(trim($header)) == 0) {
            echo "❌ Erro: `socket_read()` falhou ou recebeu dados vazios.\n";
        } else {
            echo "📥 Dados recebidos no handshake:\n$header\n"; // Mostra os dados recebidos
            handshake($clientSocket, $header, $host, $port);
        }
    
        unset($changed[array_search($serverSocket, $changed)]);
    
        echo "✅ Handshake WebSocket concluído com sucesso!\n"; // Log se o handshake for bem-sucedido
    }
    
    

    foreach ($changed as $clientSocket) {
        $data = socket_recv($clientSocket, $buffer, 1024, 0);
        if ($data === false || $data == 0) {
            removeClient($clientSocket, $clients, $rooms);
            continue;
        }

        $message = unmask($buffer);
        echo "Mensagem recebida: $message\n";

        // 🔹 Enviar a resposta para o mesmo cliente
        $responseMessage = "Servidor recebeu: " . $message;
        socket_write($clientSocket, mask($responseMessage), strlen(mask($responseMessage)));

        // Interpretar a mensagem como JSON
        $jsonData = json_decode($message, true);
        
        if (isset($jsonData['action'])) {
            if ($jsonData['action'] === "join") {
                $roomName = $jsonData['room'];
                joinRoom($clientSocket, $roomName, $rooms);
            } elseif ($jsonData['action'] === "message") {
                sendMessageToRoom($clientSocket, $jsonData['room'], $jsonData['message'], $rooms);
            }
        }
    }
}

socket_close($serverSocket);

function handshake($client, $header, $host, $port) {
    preg_match("#Sec-WebSocket-Key: (.*)\r\n#", $header, $matches);
    $key = base64_encode(pack('H*', sha1($matches[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    
    $response = "HTTP/1.1 101 Switching Protocols\r\n";
    $response .= "Upgrade: websocket\r\n";
    $response .= "Connection: Upgrade\r\n";
    $response .= "Sec-WebSocket-Accept: $key\r\n\r\n";
    
    socket_write($client, $response, strlen($response));
}

function unmask($text) {
    $length = ord($text[1]) & 127;
    if ($length == 126) {
        $masks = substr($text, 4, 4);
        $data = substr($text, 8);
    } elseif ($length == 127) {
        $masks = substr($text, 10, 4);
        $data = substr($text, 14);
    } else {
        $masks = substr($text, 2, 4);
        $data = substr($text, 6);
    }
    $text = "";
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i % 4];
    }
    return $text;
}

function mask($text) {
    $b1 = 0x81;
    $length = strlen($text);
    if ($length <= 125) {
        $header = pack('CC', $b1, $length);
    } elseif ($length <= 65535) {
        $header = pack('CCn', $b1, 126, $length);
    } else {
        $header = pack('CCNN', $b1, 127, $length, 0);
    }
    return $header . $text;
}

function joinRoom($client, $roomName, &$rooms) {
    $clientIndex = array_search($client, $GLOBALS['clients']); 
if ($clientIndex !== false) {
    $rooms[$clientIndex] = $roomName;
    echo "Jogador entrou na sala: $roomName\n";
}
}

function sendMessageToRoom($client, $roomName, $message, $rooms) {
    foreach ($rooms as $clientIndex => $room) {
        if ($room === $roomName && isset($GLOBALS['clients'][$clientIndex]) && $GLOBALS['clients'][$clientIndex] != $client) {
            $socketDestino = $GLOBALS['clients'][$clientIndex]; // Pegamos o socket correto
            $response = json_encode(["message" => $message]); // Criamos a resposta no formato JSON
            socket_write($socketDestino, mask($response), strlen(mask($response))); // Enviamos a mensagem mascarada
        }
    }
}


function removeClient($client, &$clients, &$rooms) {
    socket_close($client);

    // Encontra o índice correto do socket no array de clientes
    $key = array_search($client, $clients, true);
    if ($key !== false) {
        unset($clients[$key]);
    }

    // Remove da lista de salas se existir
    foreach ($rooms as $player => $room) {
        if ($player === $client) {
            unset($rooms[$player]);
            break;
        }
    }

    echo "Jogador desconectado.\n";
}

?>
