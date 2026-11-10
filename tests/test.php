<?php
declare(strict_types=1);

/*
 * Testes de integração independentes. Usam o próprio cookie para manter a sessão de teste isolada.
 */
$baseUrl = rtrim(getenv('API_URL') ?: 'http://localhost:8080', '/');
$runId = bin2hex(random_bytes(5));
$testDate = date('Y-m-d');
$passed = 0;
$failed = 0;
$categoryIncome = null;
$categoryExpense = null;
$transactionIncome = null;
$transactionExpense = null;
$baselineIncome = null;
$baselineExpense = null;
$cookieJar = [];

function requestApi(string $method, string $path, array|string|null $body = null): array
{
    global $baseUrl, $cookieJar;

    $headers = "Content-Type: application/json\r\n";
    if ($cookieJar !== []) {
        $cookies = [];
        foreach ($cookieJar as $name => $value) {
            $cookies[] = "$name=$value";
        }
        $headers .= 'Cookie: ' . implode('; ', $cookies) . "\r\n";
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => $headers,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ];
    if ($body !== null) {
        $options['http']['content'] = is_array($body)
            ? json_encode($body, JSON_THROW_ON_ERROR)
            : $body;
    }

    $context = stream_context_create($options);
    $response = file_get_contents($baseUrl . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#HTTP/\S+\s+(\d+)#', $header, $matches)) {
            $status = (int)$matches[1];
        }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $matches)) {
            $cookieJar[$matches[1]] = $matches[2];
        }
    }
    if ($response === false) {
        throw new RuntimeException("Não foi possível acessar a API em {$baseUrl}.");
    }

    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        throw new RuntimeException('A API retornou uma resposta que não é um JSON válido.');
    }

    return [$status, $payload];
}

function test(string $name, callable $callback): void
{
    global $passed, $failed;

    try {
        $callback();
        $passed++;
        echo "[OK] $name\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "[FALHOU] $name: {$exception->getMessage()}\n";
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectStatus(array $result, int $expected): void
{
    expect(
        $result[0] === $expected,
        "Esperado HTTP $expected, recebido {$result[0]}: " . json_encode($result[1], JSON_UNESCAPED_UNICODE)
    );
}

function expectSuccess(array $result, int $expectedStatus = 200): void
{
    expectStatus($result, $expectedStatus);
    expect(($result[1]['sucesso'] ?? false) === true, 'A resposta deveria indicar sucesso.');
}

function expectError(array $result, int $expectedStatus): void
{
    expectStatus($result, $expectedStatus);
    expect(($result[1]['sucesso'] ?? true) === false, 'A resposta deveria indicar falha.');
    expect(
        isset($result[1]['erro']) && is_string($result[1]['erro']) && $result[1]['erro'] !== '',
        'A resposta deveria conter uma mensagem de erro.'
    );
}

function expectContainsId(array $items, int $id): void
{
    foreach ($items as $item) {
        if ((int)($item['id'] ?? 0) === $id) {
            return;
        }
    }
    throw new RuntimeException("O resultado não contém o registro de ID $id.");
}

function cleanupTestData(): void
{
    global $categoryExpense, $categoryIncome, $transactionExpense, $transactionIncome;

    foreach ([$transactionIncome, $transactionExpense] as $id) {
        if ($id === null) {
            continue;
        }
        try {
            $result = requestApi('DELETE', "/lancamentos/$id");
            if (!in_array($result[0], [200, 404], true)) {
                echo "[FALHOU] Limpeza do lançamento $id: HTTP {$result[0]}.\n";
            }
        } catch (Throwable $exception) {
            echo "[FALHOU] Limpeza do lançamento $id: {$exception->getMessage()}\n";
        }
    }

    foreach ([$categoryIncome, $categoryExpense] as $id) {
        if ($id === null) {
            continue;
        }
        try {
            $result = requestApi('DELETE', "/categorias/$id");
            if (!in_array($result[0], [200, 404], true)) {
                echo "[FALHOU] Limpeza da categoria $id: HTTP {$result[0]}.\n";
            }
        } catch (Throwable $exception) {
            echo "[FALHOU] Limpeza da categoria $id: {$exception->getMessage()}\n";
        }
    }
}

register_shutdown_function('cleanupTestData');

echo "Testes da API de Controle Financeiro\n\n";

test('Consultar resumo inicial do período', function () use ($testDate, &$baselineIncome, &$baselineExpense): void {
    $result = requestApi('GET', "/resumo/periodo?data_inicial=$testDate&data_final=$testDate");
    expectSuccess($result);
    expect(
        isset($result[1]['total_receitas'], $result[1]['total_despesas']),
        'A resposta deveria conter os totais iniciais.'
    );
    $baselineIncome = (float)$result[1]['total_receitas'];
    $baselineExpense = (float)$result[1]['total_despesas'];
});

test('Cadastrar categoria de receita', function () use ($runId, &$categoryIncome): void {
    $result = requestApi('POST', '/categorias', [
        'nome' => "Teste Receita $runId",
        'tipo' => 'receita',
    ]);
    expectSuccess($result, 201);
    expect(isset($result[1]['id']) && (int)$result[1]['id'] > 0, 'A resposta deveria conter o ID criado.');
    $categoryIncome = (int)$result[1]['id'];
});

test('Rejeitar categoria duplicada', function () use ($runId): void {
    expectError(requestApi('POST', '/categorias', [
        'nome' => "Teste Receita $runId",
        'tipo' => 'receita',
    ]), 409);
});

test('Rejeitar campos de categoria inválidos', function (): void {
    expectError(requestApi('POST', '/categorias', ['nome' => [], 'tipo' => 'receita']), 422);
    expectError(requestApi('POST', '/categorias', ['nome' => 'Incompleta']), 422);
    expectError(requestApi('POST', '/categorias', ['nome' => str_repeat('a', 101), 'tipo' => 'receita']), 422);
    expectError(requestApi('POST', '/categorias', '{json inválido'), 400);
});

test('Listar categorias', function () use (&$categoryIncome): void {
    $result = requestApi('GET', '/categorias');
    expectSuccess($result);
    expect(is_array($result[1]['dados'] ?? null), 'A resposta deveria conter a lista de categorias.');
    expectContainsId($result[1]['dados'], $categoryIncome);
});

test('Consultar categoria existente', function () use (&$categoryIncome): void {
    $result = requestApi('GET', "/categorias/$categoryIncome");
    expectSuccess($result);
    expect((int)($result[1]['dados']['id'] ?? 0) === $categoryIncome, 'A resposta deveria conter a categoria consultada.');
});

test('Consultar categoria inexistente', function (): void {
    expectError(requestApi('GET', '/categorias/999999999'), 404);
});

test('Atualizar categoria', function () use ($runId, &$categoryIncome): void {
    $result = requestApi('PUT', "/categorias/$categoryIncome", [
        'nome' => "Receita Atualizada $runId",
        'tipo' => 'receita',
    ]);
    expectSuccess($result);
});

test('Rejeitar categoria inválida na atualização', function () use (&$categoryIncome): void {
    expectError(requestApi('PUT', "/categorias/$categoryIncome", [
        'nome' => 'Categoria inválida',
        'tipo' => 'outro',
    ]), 422);
});

test('Rejeitar atualização de categoria inexistente', function (): void {
    expectError(requestApi('PUT', '/categorias/999999999', [
        'nome' => 'Não existe',
        'tipo' => 'receita',
    ]), 404);
});

test('Cadastrar categoria de despesa', function () use ($runId, &$categoryExpense): void {
    $result = requestApi('POST', '/categorias', [
        'nome' => "Teste Despesa $runId",
        'tipo' => 'despesa',
    ]);
    expectSuccess($result, 201);
    $categoryExpense = (int)$result[1]['id'];
});

test('Cadastrar lançamento de receita', function () use (&$categoryIncome, $testDate, &$transactionIncome): void {
    $result = requestApi('POST', '/lancamentos', [
        'descricao' => 'Receita de teste',
        'valor' => 2500,
        'data' => $testDate,
        'tipo' => 'receita',
        'categoria_id' => $categoryIncome,
    ]);
    expectSuccess($result, 201);
    expect(isset($result[1]['id']) && (int)$result[1]['id'] > 0, 'A resposta deveria conter o ID criado.');
    $transactionIncome = (int)$result[1]['id'];
});

test('Rejeitar alteração de tipo de categoria com lançamento vinculado', function () use (&$categoryIncome, $runId): void {
    expectError(requestApi('PUT', "/categorias/$categoryIncome", [
        'nome' => "Tipo incompatível $runId",
        'tipo' => 'despesa',
    ]), 409);
});

test('Rejeitar lançamento com valor zero', function () use (&$categoryIncome, $testDate): void {
    expectError(requestApi('POST', '/lancamentos', [
        'descricao' => 'Inválido',
        'valor' => 0,
        'data' => $testDate,
        'tipo' => 'receita',
        'categoria_id' => $categoryIncome,
    ]), 422);
});

test('Rejeitar lançamento com categoria inexistente', function () use ($testDate): void {
    expectError(requestApi('POST', '/lancamentos', [
        'descricao' => 'Inválido',
        'valor' => 10,
        'data' => $testDate,
        'tipo' => 'receita',
        'categoria_id' => 999999999,
    ]), 422);
});

test('Rejeitar lançamento com tipo incompatível', function () use (&$categoryIncome, $testDate): void {
    expectError(requestApi('POST', '/lancamentos', [
        'descricao' => 'Inválido',
        'valor' => 10,
        'data' => $testDate,
        'tipo' => 'despesa',
        'categoria_id' => $categoryIncome,
    ]), 422);
});

test('Rejeitar campos de lançamento inválidos', function () use (&$categoryIncome, $testDate): void {
    expectError(requestApi('POST', '/lancamentos', [
        'descricao' => [],
        'valor' => 10,
        'data' => $testDate,
        'tipo' => 'receita',
        'categoria_id' => $categoryIncome,
    ]), 422);
    expectError(requestApi('POST', '/lancamentos', [
        'descricao' => 'Sem categoria',
        'valor' => 10,
        'data' => $testDate,
        'tipo' => 'receita',
    ]), 422);
    expectError(requestApi('POST', '/lancamentos', [
        'descricao' => str_repeat('a', 256),
        'valor' => 10,
        'data' => $testDate,
        'tipo' => 'receita',
        'categoria_id' => $categoryIncome,
    ]), 422);
    expectError(requestApi('POST', '/lancamentos', [
        'descricao' => 'Valor fora do limite',
        'valor' => '1e999',
        'data' => $testDate,
        'tipo' => 'receita',
        'categoria_id' => $categoryIncome,
    ]), 422);
});

test('Cadastrar lançamento de despesa', function () use (&$categoryExpense, $testDate, &$transactionExpense): void {
    $result = requestApi('POST', '/lancamentos', [
        'descricao' => 'Despesa de teste',
        'valor' => 75.20,
        'data' => $testDate,
        'tipo' => 'despesa',
        'categoria_id' => $categoryExpense,
    ]);
    expectSuccess($result, 201);
    $transactionExpense = (int)$result[1]['id'];
});

test('Listar lançamentos', function () use (&$transactionIncome, &$transactionExpense): void {
    $result = requestApi('GET', '/lancamentos');
    expectSuccess($result);
    expect(is_array($result[1]['dados'] ?? null), 'A resposta deveria conter a lista de lançamentos.');
    expectContainsId($result[1]['dados'], $transactionIncome);
    expectContainsId($result[1]['dados'], $transactionExpense);
});

test('Filtrar lançamentos por período', function () use ($testDate, &$transactionIncome): void {
    $result = requestApi('GET', "/lancamentos?data_inicial=$testDate&data_final=$testDate");
    expectSuccess($result);
    expectContainsId($result[1]['dados'], $transactionIncome);
});

test('Rejeitar filtro por período inválido', function (): void {
    expectError(requestApi('GET', '/lancamentos?data_inicial=2026-02-30&data_final=2026-03-01'), 422);
    expectError(requestApi('GET', '/lancamentos?data_inicial=2026-03-01'), 422);
    expectError(requestApi('GET', '/lancamentos?data_inicial=2026-03-02&data_final=2026-03-01'), 422);
});

test('Filtrar lançamentos por tipo', function () use (&$transactionIncome): void {
    $result = requestApi('GET', '/lancamentos?tipo=receita');
    expectSuccess($result);
    expectContainsId($result[1]['dados'], $transactionIncome);
});

test('Rejeitar filtro por tipo inválido', function (): void {
    expectError(requestApi('GET', '/lancamentos?tipo=outro'), 422);
});

test('Filtrar lançamentos por categoria', function () use (&$categoryIncome, &$transactionIncome): void {
    $result = requestApi('GET', '/lancamentos?categoria_id=' . $categoryIncome);
    expectSuccess($result);
    expectContainsId($result[1]['dados'], $transactionIncome);
});

test('Rejeitar filtro por categoria inválida', function (): void {
    expectError(requestApi('GET', '/lancamentos?categoria_id=abc'), 422);
    expectError(requestApi('GET', '/lancamentos?categoria_id%5B%5D=1'), 422);
});

test('Consultar lançamento existente', function () use (&$transactionIncome): void {
    $result = requestApi('GET', "/lancamentos/$transactionIncome");
    expectSuccess($result);
    expect((int)($result[1]['dados']['id'] ?? 0) === $transactionIncome, 'A resposta deveria conter o lançamento consultado.');
});

test('Consultar lançamento inexistente', function (): void {
    expectError(requestApi('GET', '/lancamentos/999999999'), 404);
});

test('Atualizar lançamento', function () use (&$transactionIncome, &$categoryIncome, $testDate): void {
    $result = requestApi('PUT', "/lancamentos/$transactionIncome", [
        'descricao' => 'Receita atualizada',
        'valor' => 2600,
        'data' => $testDate,
        'tipo' => 'receita',
        'categoria_id' => $categoryIncome,
    ]);
    expectSuccess($result);
});

test('Rejeitar lançamento inválido na atualização', function () use (&$transactionIncome, &$categoryIncome, $testDate): void {
    expectError(requestApi('PUT', "/lancamentos/$transactionIncome", [
        'descricao' => 'Valor inválido',
        'valor' => 0,
        'data' => $testDate,
        'tipo' => 'receita',
        'categoria_id' => $categoryIncome,
    ]), 422);
});

test('Rejeitar atualização de lançamento inexistente', function () use (&$categoryIncome, $testDate): void {
    expectError(requestApi('PUT', '/lancamentos/999999999', [
        'descricao' => 'Não existe',
        'valor' => 10,
        'data' => $testDate,
        'tipo' => 'receita',
        'categoria_id' => $categoryIncome,
    ]), 404);
});

test('Consultar saldo e verificar cálculo', function (): void {
    $result = requestApi('GET', '/resumo/saldo');
    expectSuccess($result);
    expect(
        isset($result[1]['receitas'], $result[1]['despesas'], $result[1]['saldo']),
        'A resposta deveria conter receitas, despesas e saldo.'
    );
    $expectedBalance = round((float)$result[1]['receitas'] - (float)$result[1]['despesas'], 2);
    expect((float)$result[1]['saldo'] === $expectedBalance, 'O saldo deveria ser receitas menos despesas.');
});

test('Consultar totais do período e verificar valores', function () use ($testDate, &$baselineIncome, &$baselineExpense): void {
    $result = requestApi('GET', "/resumo/periodo?data_inicial=$testDate&data_final=$testDate");
    expectSuccess($result);
    expect(
        round((float)($result[1]['total_receitas'] ?? -1) - $baselineIncome, 2) === 2600.0
        && round((float)($result[1]['total_despesas'] ?? -1) - $baselineExpense, 2) === 75.2,
        'Os totais do período não correspondem aos lançamentos de teste.'
    );
});

test('Rejeitar período inválido no resumo', function (): void {
    expectError(requestApi('GET', '/resumo/periodo?data_inicial=2026-02-30&data_final=2026-03-01'), 422);
});

test('Impedir exclusão de categoria vinculada', function () use (&$categoryIncome): void {
    expectError(requestApi('DELETE', "/categorias/$categoryIncome"), 409);
});

test('Excluir lançamento', function () use (&$transactionIncome): void {
    expectSuccess(requestApi('DELETE', "/lancamentos/$transactionIncome"));
    $transactionIncome = null;
});

test('Excluir lançamento inexistente', function (): void {
    expectError(requestApi('DELETE', '/lancamentos/999999999'), 404);
});

test('Excluir segundo lançamento', function () use (&$transactionExpense): void {
    expectSuccess(requestApi('DELETE', "/lancamentos/$transactionExpense"));
    $transactionExpense = null;
});

test('Excluir categorias sem lançamentos', function () use (&$categoryIncome, &$categoryExpense): void {
    expectSuccess(requestApi('DELETE', "/categorias/$categoryIncome"));
    $categoryIncome = null;
    expectSuccess(requestApi('DELETE', "/categorias/$categoryExpense"));
    $categoryExpense = null;
});

test('Excluir categoria inexistente', function (): void {
    expectError(requestApi('DELETE', '/categorias/999999999'), 404);
});

echo "\nResultado: $passed aprovados; $failed falharam.\n";
exit($failed > 0 ? 1 : 0);
