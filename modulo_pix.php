<?php
//author: Lucas Farias
//site: lf.dev.br
//GIT: lucasfariasrj1
//email: lucasfariasrj1@gmail.com


if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as DB;

function modulo_pix_config() {
    return [
        'name' => 'Módulo PIX Avulso',
        'description' => 'Gera cobranças PIX avulsas utilizando a API PagHiper.',
        'version' => '1.0',
        'author' => 'Lucas F',
        'fields' => [
            'apiKey' => [
                'FriendlyName' => 'API Key',
                'Type' => 'text',
                'Size' => '50',
                'Description' => 'Insira sua API Key da PagHiper',
            ],
            'cpfFieldId' => [
                'FriendlyName' => 'ID do Campo CPF/CNPJ',
                'Type' => 'text',
                'Size' => '20',
                'Description' => 'Insira o ID do campo personalizado para CPF ou CNPJ',
            ],
        ]
    ];
}

function modulo_pix_activate() {
    try {
        DB::schema()->create('mod_pix_avulso', function ($table) {
            $table->increments('id');
            $table->string('client_name', 100);
            $table->string('client_id', 100);
            $table->string('valor', 100);
            $table->string('status', 100);
            $table->date('data');
            $table->string('transaction_id', 50);
            $table->dateTime('created_date');
            $table->integer('value_cents');
            $table->string('order_id', 50);
            $table->date('due_date');
            $table->text('qrcode_base64');
            $table->text('qrcode_image_url');
            $table->text('emv');
            $table->text('pix_url');
            $table->text('bacen_url');
        });

        return ['status' => 'success', 'description' => 'Módulo ativado com sucesso e tabela criada.'];
    } catch (Exception $e) {
        return ['status' => 'error', 'description' => 'Erro ao criar tabela: ' . $e->getMessage()];
    }
}

function modulo_pix_deactivate() {
    try {
        DB::schema()->dropIfExists('mod_pix_avulso');
        return ['status' => 'success', 'description' => 'Módulo desativado com sucesso e tabela removida.'];
    } catch (Exception $e) {
        return ['status' => 'error', 'description' => 'Erro ao remover tabela: ' . $e->getMessage()];
    }
}

function modulo_pix_output($vars) {
    $apiKey = $vars['apiKey'];
    $cpfFieldId = $vars['cpfFieldId'];

    $clients = DB::table('tblclients')->get();

    $clientOptions = "";
    foreach ($clients as $client) {
        $clientOptions .= "<option value='{$client->id}'>{$client->firstname} {$client->lastname} ({$client->email})</option>";
    }

    $pixInfoHtml = ""; // Variável para armazenar as informações do PIX

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $clientId = $_POST['client'];
        $value = $_POST['value'];

        if (!$clientId || !$value) {
            echo "<p class='error'>Erro: Selecione um cliente e insira o valor do PIX.</p>";
            return;
        }

        $client = DB::table('tblclients')->where('id', $clientId)->first();
        $cpfCnpj = DB::table('tblcustomfieldsvalues')
            ->where('relid', $clientId)
            ->where('fieldid', $cpfFieldId)
            ->value('value');

        $priceCents = intval($value * 100); 
        $quantity = 1;

        if ($priceCents <= 0) {
            echo "<p class='error'>Erro: Valor do PIX inválido.</p>";
            return;
        }

        $params = [
            "apiKey" => $apiKey,
            "order_id" => "WHMCS-" . time(),
            "payer_email" => $client->email,
            "payer_name" => "{$client->firstname} {$client->lastname}",
            "payer_cpf_cnpj" => $cpfCnpj,
            "payer_phone" => "{$client->phonenumber}", 
            "notification_url" => "https://seusite.com/notify/paghiper/", 
            "discount_cents" => "0",
            "shipping_price_cents" => "0", 
            "shipping_methods" => "PIX",
            "fixed_description" => true,
            "days_due_date" => 1,
            "items" => [
                [
                    "description" => "PIX Avulso",
                    "quantity" => $quantity,
                    "item_id" => "1", // ID fixo para representar PIX Avulso
                    "price_cents" => $priceCents
                ]
            ]
        ];

        $response = httpPost("https://pix.paghiper.com/invoice/create/", $params);
        $responseData = json_decode($response, true);

        if (isset($responseData['pix_create_request']['result']) && $responseData['pix_create_request']['result'] == 'success') {
            $qrCodeUrl = $responseData['pix_create_request']['pix_code']['qrcode_image_url'];
            $pixUrl = $responseData['pix_create_request']['pix_code']['pix_url'];
            $emvCode = $responseData['pix_create_request']['pix_code']['emv'];
            $valueFormatted = number_format($priceCents / 100, 2, ',', '.');

            // Registrar a cobrança PIX no banco de dados
            DB::table('mod_pix_avulso')->insert([
                'client_name' => "{$client->firstname} {$client->lastname}",
                'client_id' => $clientId,
                'valor' => $valueFormatted,
                'status' => 'Pendente',
                'data' => date('Y-m-d'),
                'transaction_id' => $responseData['pix_create_request']['transaction_id'],
                'created_date' => date('Y-m-d H:i:s', strtotime($responseData['pix_create_request']['created_date'])),
                'value_cents' => $priceCents,
                'order_id' => $params['order_id'],
                'due_date' => date('Y-m-d', strtotime($responseData['pix_create_request']['due_date'])),
                'qrcode_base64' => $responseData['pix_create_request']['pix_code']['qrcode_base64'],
                'qrcode_image_url' => $responseData['pix_create_request']['pix_code']['qrcode_image_url'],
                'emv' => $responseData['pix_create_request']['pix_code']['emv'],
                'pix_url' => $responseData['pix_create_request']['pix_code']['pix_url'],
                'bacen_url' => $responseData['pix_create_request']['pix_code']['bacen_url']
            ]);

            $pixInfoHtml = "
                <div class='pix-info'>
                    <h3>PIX Gerado com Sucesso</h3>
                    <div class='pix-info-content'>
                        <img src='$qrCodeUrl' alt='QR Code do PIX' class='qr-code'>
                        <p><strong>Valor:</strong> R$ $valueFormatted</p>
                        <p><strong>Código para Copiar e Colar:</strong></p>
                        <p id='emv-code' class='emv-code'>$emvCode</p>
                        <p><strong>Link do PIX:</strong></p>
                        <p><a href='$pixUrl' target='_blank'>$pixUrl</a></p> <!-- Exibe a URL do PIX como link clicável -->
                        <p><strong>Expira em 1 dia</strong></p>
                    </div>
                </div>";
        } else {
            $pixInfoHtml = "<p class='error'>Erro ao gerar PIX: " . ($responseData['pix_create_request']['response_message'] ?? 'Erro desconhecido') . "</p>";
        }
    }

    // Obter todas as cobranças PIX do banco de dados
    $pixCharges = DB::table('mod_pix_avulso')->get();

    // Construir a tabela HTML
    $pixTableHtml = "
        <table class='pix-table'>
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>ID do Cliente</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th>ID da Transação</th>
                    <th>ID do Pedido</th>
                </tr>
            </thead>
            <tbody>";

    foreach ($pixCharges as $charge) {
        $pixTableHtml .= "
                <tr>
                    <td>{$charge->client_name}</td>
                    <td>{$charge->client_id}</td>
                    <td>R$ {$charge->valor}</td>
                    <td>{$charge->status}</td>
                    <td>{$charge->data}</td>
                    <td>{$charge->transaction_id}</td>
                    <td>{$charge->order_id}</td>
                </tr>";
    }

    $pixTableHtml .= "
            </tbody>
        </table>";

    echo <<<HTML
        <style>
            .pix-form {
                max-width: 600px;
                margin: 20px auto;
                padding: 20px;
                background-color: #f9f9f9;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }
            .pix-form h2 {
                margin-bottom: 20px;
                color: #333;
                text-align: center;
            }
            .pix-form label {
                display: block;
                margin-bottom: 8px;
                font-weight: bold;
                color: #555;
            }
            .pix-form select, .pix-form input[type="text"], .pix-form input[type="number"] {
                width: 100%;
                padding: 10px;
                margin-bottom: 15px;
                border: 1px solid #ccc;
                border-radius: 4px;
            }
            .pix-form input[type="submit"] {
                background-color: #28a745;
                color: white;
                border: none;
                padding: 10px 20px;
                cursor: pointer;
                border-radius: 4px;
                font-size: 16px;
            }
            .pix-form input[type="submit"]:hover {
                background-color: #218838;
            }
            .pix-form .success {
                color: green;
                margin-top: 20px;
            }
            .pix-form .error {
                color: red;
                margin-top: 20px;
            }
            .pix-info {
                margin-top: 20px;
                padding: 20px;
                border: 1px solid #ccc;
                border-radius: 8px;
                background-color: #f1f1f1;
                text-align: center;
            }
            .pix-info-content {
                max-width: 400px;
                margin: 0 auto;
            }
            .pix-info h3 {
                margin-bottom: 15px;
                color: #333;
            }
            .qr-code {
                max-width: 100%;
                height: auto;
                margin-bottom: 15px;
            }
            .emv-code {
                word-wrap: break-word;
                font-family: monospace;
                background-color: #fff;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .pix-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            .pix-table th, .pix-table td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            .pix-table th {
                background-color: #f2f2f2;
                color: #333;
            }
        </style>

        <div class="pix-form">
            <h2>Gerar PIX Avulso</h2>
            <form method="post">
                <label for="client">Selecione o Cliente:</label>
                <select name="client" id="client">
                    $clientOptions
                </select>
                <br>
                <label for="value">Valor do PIX (R$):</label>
                <input type="number" name="value" id="value" step="0.01" min="0" required>
                <br>
                <input type="submit" value="Gerar PIX">
            </form>
        </div>

        <!-- Exibição das informações do PIX gerado -->
        $pixInfoHtml

        <!-- Tabela com todas as cobranças PIX -->
        $pixTableHtml
    HTML;
}

function httpPost($url, $params) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Accept: application/json'
    ));
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
}

?>
