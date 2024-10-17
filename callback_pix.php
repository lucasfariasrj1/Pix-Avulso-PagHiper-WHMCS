<?php


use Illuminate\Database\Capsule\Manager as DB;

function processPixCallback() {
    // Verifica se a solicitação é um POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método não permitido']);
        exit;
    }

    // Obtém o corpo da solicitação
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Verifica se os dados necessários estão presentes
    if (!isset($data['status_request'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados inválidos']);
        exit;
    }

    // Obtém os dados do webhook
    $statusRequest = $data['status_request'];
    $transactionId = $statusRequest['transaction_id'] ?? null;
    $orderId = $statusRequest['order_id'] ?? null;
    $createdDate = $statusRequest['created_date'] ?? null;
    $status = $statusRequest['status'] ?? null;
    $payerEmail = $statusRequest['payer_email'] ?? null;
    $payerName = $statusRequest['payer_name'] ?? null;
    $payerCpfCnpj = $statusRequest['payer_cpf_cnpj'] ?? null;
    $statusDate = $statusRequest['status_date'] ?? null;
    $valueCents = $statusRequest['value_cents'] ?? null;
    $dueDate = $statusRequest['due_date'] ?? null;
    $pixCode = $statusRequest['pix_code'] ?? null;
    $qrcodeBase64 = $pixCode['qrcode_base64'] ?? null;
    $qrcodeImageUrl = $pixCode['qrcode_image_url'] ?? null;
    $emv = $pixCode['emv'] ?? null;
    $pixUrl = $pixCode['pix_url'] ?? null;
    $bacenUrl = $pixCode['bacen_url'] ?? null;

    try {
        // Verifica se a cobrança já existe na tabela
        $existingCharge = DB::table('mod_pix_avulso')
            ->where('transaction_id', $transactionId)
            ->first();

        if ($existingCharge) {
            // Atualiza o status para 'paid' se o status no webhook for 'paid'
            if ($status === 'paid') {
                DB::table('mod_pix_avulso')
                    ->where('transaction_id', $transactionId)
                    ->update(['status' => 'paid']);
            }
        } else {
            // Insere uma nova cobrança na tabela
            DB::table('mod_pix_avulso')->insert([
                'client_name' => $payerName,
                'client_id' => $payerCpfCnpj,
                'valor' => number_format($valueCents / 100, 2, ',', '.'),
                'status' => $status,
                'data' => date('Y-m-d'),
                'transaction_id' => $transactionId,
                'created_date' => $createdDate,
                'value_cents' => $valueCents,
                'order_id' => $orderId,
                'due_date' => $dueDate,
                'qrcode_base64' => $qrcodeBase64,
                'qrcode_image_url' => $qrcodeImageUrl,
                'emv' => $emv,
                'pix_url' => $pixUrl,
                'bacen_url' => $bacenUrl
            ]);
        }

        http_response_code(200);
        echo json_encode(['result' => 'success']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao processar dados: ' . $e->getMessage()]);
    }
}

processPixCallback();

?>
