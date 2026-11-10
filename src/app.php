<?php
declare(strict_types=1);

final class SessionStore
{
    public function read(): array
    {
        return $_SESSION['controle_financeiro'] ?? [
            'categorias' => [],
            'lancamentos' => [],
            'proximos_ids' => ['categorias' => 1, 'lancamentos' => 1],
        ];
    }

    public function transaction(callable $operation): mixed
    {
        $state = $this->read();
        $result = $operation($state);
        $_SESSION['controle_financeiro'] = $state;
        return $result;
    }
}

final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status, array $details = []): never
    {
        self::json(array_merge([
            'sucesso' => false,
            'erro' => $message,
        ], $details), $status);
    }
}

final class Validator
{
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            Response::error('O corpo da requisição deve conter um JSON válido.', 400);
        }
        return $data;
    }

    public static function required(array $data, array $fields): void
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }
        if ($missing) {
            Response::error('Campos obrigatórios ausentes ou vazios.', 422, ['campos' => $missing]);
        }
    }

    public static function type(string $type): bool
    {
        return in_array($type, ['receita', 'despesa'], true);
    }

    public static function amount(mixed $value): bool
    {
        if (
            (!is_int($value) && !is_float($value) && !is_string($value))
            || $value === ''
            || !is_numeric($value)
        ) {
            return false;
        }

        $amount = (float)$value;
        return is_finite($amount)
            && round($amount, 2) > 0
            && $amount <= 9999999999.99;
    }

    public static function textLengthAtMost(string $value, int $maxLength): bool
    {
        $length = preg_match_all('/./us', $value);
        return $length !== false && $length <= $maxLength;
    }

    public static function date(string $date): bool
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!session_start()) {
        Response::error('Não foi possível iniciar a sessão.', 500);
    }
}

function findById(array $items, int $id): ?array
{
    foreach ($items as $item) {
        if ((int)$item['id'] === $id) {
            return $item;
        }
    }
    return null;
}

function namesMatch(string $left, string $right): bool
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($left, 'UTF-8') === mb_strtolower($right, 'UTF-8');
    }
    return strtolower($left) === strtolower($right);
}

function formatTransaction(array $transaction, array $categories): array
{
    $category = findById($categories, (int)$transaction['categoria_id']);
    $transaction['valor'] = (float)$transaction['valor'];
    $transaction['categoria'] = $category['nome'] ?? null;
    return $transaction;
}

try {
    $store = new SessionStore();
    $method = $_SERVER['REQUEST_METHOD'];
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $path = '/' . trim(preg_replace('#^/api(?=/|$)#', '', $requestPath), '/');
    if ($path === '//') {
        $path = '/';
    }

    if ($path === '/categorias' && $method === 'POST') {
        $data = Validator::body();
        Validator::required($data, ['nome', 'tipo']);
        if (!is_string($data['nome']) || !is_string($data['tipo'])) {
            Response::error('Nome ou tipo inválido. Tipo deve ser receita ou despesa.', 422);
        }

        $nome = trim($data['nome']);
        $tipo = $data['tipo'];
        if ($nome === '' || !Validator::textLengthAtMost($nome, 100) || !Validator::type($tipo)) {
            Response::error('Nome ou tipo inválido. Tipo deve ser receita ou despesa.', 422);
        }

        $id = $store->transaction(function (array &$state) use ($nome, $tipo): int {
            foreach ($state['categorias'] as $category) {
                if (namesMatch($category['nome'], $nome)) {
                    throw new DomainException('Já existe uma categoria com esse nome.', 409);
                }
            }

            $id = (int)$state['proximos_ids']['categorias']++;
            $state['categorias'][] = [
                'id' => $id,
                'nome' => $nome,
                'tipo' => $tipo,
                'criado_em' => date('Y-m-d H:i:s'),
            ];
            return $id;
        });

        Response::json(['sucesso' => true, 'mensagem' => 'Categoria cadastrada.', 'id' => $id], 201);
    }

    if ($path === '/categorias' && $method === 'GET') {
        $state = $store->read();
        $categories = $state['categorias'];
        usort($categories, static fn (array $left, array $right): int => strcasecmp($left['nome'], $right['nome']));
        Response::json(['sucesso' => true, 'dados' => $categories]);
    }

    if (preg_match('#^/categorias/(\d+)$#', $path, $matches)) {
        $id = (int)$matches[1];

        if ($method === 'GET') {
            $state = $store->read();
            $category = findById($state['categorias'], $id);
            if ($category === null) {
                Response::error('Categoria não encontrada.', 404);
            }
            Response::json(['sucesso' => true, 'dados' => $category]);
        }

        if ($method === 'PUT') {
            $data = Validator::body();
            Validator::required($data, ['nome', 'tipo']);
            if (!is_string($data['nome']) || !is_string($data['tipo'])) {
                Response::error('Nome ou tipo inválido.', 422);
            }

            $nome = trim($data['nome']);
            $tipo = $data['tipo'];
            if ($nome === '' || !Validator::textLengthAtMost($nome, 100) || !Validator::type($tipo)) {
                Response::error('Nome ou tipo inválido.', 422);
            }

            $store->transaction(function (array &$state) use ($id, $nome, $tipo): void {
                $categoryIndex = null;
                foreach ($state['categorias'] as $index => $category) {
                    if ((int)$category['id'] === $id) {
                        $categoryIndex = $index;
                        continue;
                    }
                    if (namesMatch($category['nome'], $nome)) {
                        throw new DomainException('Já existe uma categoria com esse nome.', 409);
                    }
                }
                if ($categoryIndex === null) {
                    throw new DomainException('Categoria não encontrada.', 404);
                }

                foreach ($state['lancamentos'] as $transaction) {
                    if ((int)$transaction['categoria_id'] === $id && $transaction['tipo'] !== $tipo) {
                        throw new DomainException('O tipo da categoria não pode divergir dos lançamentos vinculados.', 409);
                    }
                }

                $state['categorias'][$categoryIndex]['nome'] = $nome;
                $state['categorias'][$categoryIndex]['tipo'] = $tipo;
            });

            Response::json(['sucesso' => true, 'mensagem' => 'Categoria atualizada.']);
        }

        if ($method === 'DELETE') {
            $store->transaction(function (array &$state) use ($id): void {
                $categoryIndex = null;
                foreach ($state['categorias'] as $index => $category) {
                    if ((int)$category['id'] === $id) {
                        $categoryIndex = $index;
                        break;
                    }
                }
                if ($categoryIndex === null) {
                    throw new DomainException('Categoria não encontrada.', 404);
                }
                foreach ($state['lancamentos'] as $transaction) {
                    if ((int)$transaction['categoria_id'] === $id) {
                        throw new DomainException('Não é permitido excluir categoria com lançamentos vinculados.', 409);
                    }
                }
                array_splice($state['categorias'], $categoryIndex, 1);
            });

            Response::json(['sucesso' => true, 'mensagem' => 'Categoria excluída.']);
        }
    }

    if ($path === '/lancamentos' && $method === 'POST') {
        $data = Validator::body();
        Validator::required($data, ['descricao', 'valor', 'data', 'tipo', 'categoria_id']);
        if (
            !is_string($data['descricao'])
            || !Validator::amount($data['valor'])
            || !is_string($data['data'])
            || !is_string($data['tipo'])
            || (!is_int($data['categoria_id']) && !is_string($data['categoria_id']))
        ) {
            Response::error('Dados do lançamento inválidos. Confira valor, data, tipo e categoria.', 422);
        }

        $descricao = trim($data['descricao']);
        $valor = filter_var($data['valor'], FILTER_VALIDATE_FLOAT);
        $date = $data['data'];
        $tipo = $data['tipo'];
        $categoriaId = filter_var($data['categoria_id'], FILTER_VALIDATE_INT);
        if (
            $descricao === ''
            || !Validator::textLengthAtMost($descricao, 255)
            || $valor === false
            || !Validator::amount($valor)
            || !Validator::date($date)
            || !Validator::type($tipo)
            || $categoriaId === false
            || $categoriaId <= 0
        ) {
            Response::error('Dados do lançamento inválidos. Confira valor, data, tipo e categoria.', 422);
        }
        $valor = round((float)$valor, 2);

        $id = $store->transaction(function (array &$state) use ($descricao, $valor, $date, $tipo, $categoriaId): int {
            $category = findById($state['categorias'], $categoriaId);
            if ($category === null) {
                throw new DomainException('A categoria informada não existe.', 422);
            }
            if ($category['tipo'] !== $tipo) {
                throw new DomainException('O tipo do lançamento deve ser compatível com o tipo da categoria.', 422);
            }

            $id = (int)$state['proximos_ids']['lancamentos']++;
            $state['lancamentos'][] = [
                'id' => $id,
                'descricao' => $descricao,
                'valor' => $valor,
                'data' => $date,
                'tipo' => $tipo,
                'categoria_id' => $categoriaId,
            ];
            return $id;
        });

        Response::json(['sucesso' => true, 'mensagem' => 'Lançamento cadastrado.', 'id' => $id], 201);
    }

    if ($path === '/lancamentos' && $method === 'GET') {
        $startDate = null;
        $endDate = null;
        if (isset($_GET['data_inicial']) || isset($_GET['data_final'])) {
            if (
                !isset($_GET['data_inicial'], $_GET['data_final'])
                || !is_string($_GET['data_inicial'])
                || !is_string($_GET['data_final'])
                || !Validator::date($_GET['data_inicial'])
                || !Validator::date($_GET['data_final'])
            ) {
                Response::error('Informe data_inicial e data_final válidas no formato YYYY-MM-DD.', 422);
            }
            if ($_GET['data_inicial'] > $_GET['data_final']) {
                Response::error('A data inicial não pode ser maior que a data final.', 422);
            }
            $startDate = $_GET['data_inicial'];
            $endDate = $_GET['data_final'];
        }

        $categoryId = null;
        if (isset($_GET['categoria_id'])) {
            $categoryId = is_string($_GET['categoria_id'])
                ? filter_var($_GET['categoria_id'], FILTER_VALIDATE_INT)
                : false;
            if ($categoryId === false || $categoryId <= 0) {
                Response::error('categoria_id inválido.', 422);
            }
        }

        $type = null;
        if (isset($_GET['tipo'])) {
            if (!is_string($_GET['tipo']) || !Validator::type($_GET['tipo'])) {
                Response::error('Tipo deve ser receita ou despesa.', 422);
            }
            $type = $_GET['tipo'];
        }

        $state = $store->read();
        $transactions = [];
        foreach ($state['lancamentos'] as $transaction) {
            if ($startDate !== null && ($transaction['data'] < $startDate || $transaction['data'] > $endDate)) {
                continue;
            }
            if ($categoryId !== null && (int)$transaction['categoria_id'] !== $categoryId) {
                continue;
            }
            if ($type !== null && $transaction['tipo'] !== $type) {
                continue;
            }
            $transactions[] = formatTransaction($transaction, $state['categorias']);
        }
        usort($transactions, static fn (array $left, array $right): int => [$right['data'], $right['id']] <=> [$left['data'], $left['id']]);
        Response::json(['sucesso' => true, 'dados' => $transactions]);
    }

    if (preg_match('#^/lancamentos/(\d+)$#', $path, $matches)) {
        $id = (int)$matches[1];

        if ($method === 'GET') {
            $state = $store->read();
            $transaction = findById($state['lancamentos'], $id);
            if ($transaction === null) {
                Response::error('Lançamento não encontrado.', 404);
            }
            Response::json(['sucesso' => true, 'dados' => formatTransaction($transaction, $state['categorias'])]);
        }

        if ($method === 'PUT') {
            $data = Validator::body();
            Validator::required($data, ['descricao', 'valor', 'data', 'tipo', 'categoria_id']);
            if (
                !is_string($data['descricao'])
                || !Validator::amount($data['valor'])
                || !is_string($data['data'])
                || !is_string($data['tipo'])
                || (!is_int($data['categoria_id']) && !is_string($data['categoria_id']))
            ) {
                Response::error('Dados do lançamento inválidos.', 422);
            }

            $descricao = trim($data['descricao']);
            $valor = filter_var($data['valor'], FILTER_VALIDATE_FLOAT);
            $date = $data['data'];
            $tipo = $data['tipo'];
            $categoriaId = filter_var($data['categoria_id'], FILTER_VALIDATE_INT);
            if (
                $descricao === ''
                || !Validator::textLengthAtMost($descricao, 255)
                || $valor === false
                || !Validator::amount($valor)
                || !Validator::date($date)
                || !Validator::type($tipo)
                || $categoriaId === false
                || $categoriaId <= 0
            ) {
                Response::error('Dados do lançamento inválidos.', 422);
            }
            $valor = round((float)$valor, 2);

            $store->transaction(function (array &$state) use ($id, $descricao, $valor, $date, $tipo, $categoriaId): void {
                $transactionIndex = null;
                foreach ($state['lancamentos'] as $index => $transaction) {
                    if ((int)$transaction['id'] === $id) {
                        $transactionIndex = $index;
                        break;
                    }
                }
                if ($transactionIndex === null) {
                    throw new DomainException('Lançamento não encontrado.', 404);
                }

                $category = findById($state['categorias'], $categoriaId);
                if ($category === null) {
                    throw new DomainException('A categoria informada não existe.', 422);
                }
                if ($category['tipo'] !== $tipo) {
                    throw new DomainException('O tipo do lançamento deve ser compatível com o tipo da categoria.', 422);
                }

                $state['lancamentos'][$transactionIndex] = [
                    'id' => $id,
                    'descricao' => $descricao,
                    'valor' => $valor,
                    'data' => $date,
                    'tipo' => $tipo,
                    'categoria_id' => $categoriaId,
                ];
            });

            Response::json(['sucesso' => true, 'mensagem' => 'Lançamento atualizado.']);
        }

        if ($method === 'DELETE') {
            $store->transaction(function (array &$state) use ($id): void {
                foreach ($state['lancamentos'] as $index => $transaction) {
                    if ((int)$transaction['id'] === $id) {
                        array_splice($state['lancamentos'], $index, 1);
                        return;
                    }
                }
                throw new DomainException('Lançamento não encontrado.', 404);
            });

            Response::json(['sucesso' => true, 'mensagem' => 'Lançamento excluído.']);
        }
    }

    if ($path === '/resumo/saldo' && $method === 'GET') {
        $state = $store->read();
        $income = 0.0;
        $expenses = 0.0;
        foreach ($state['lancamentos'] as $transaction) {
            if ($transaction['tipo'] === 'receita') {
                $income += (float)$transaction['valor'];
            } else {
                $expenses += (float)$transaction['valor'];
            }
        }
        $income = round($income, 2);
        $expenses = round($expenses, 2);
        Response::json([
            'sucesso' => true,
            'receitas' => $income,
            'despesas' => $expenses,
            'saldo' => round($income - $expenses, 2),
        ]);
    }

    if ($path === '/resumo/periodo' && $method === 'GET') {
        if (
            !isset($_GET['data_inicial'], $_GET['data_final'])
            || !is_string($_GET['data_inicial'])
            || !is_string($_GET['data_final'])
            || !Validator::date($_GET['data_inicial'])
            || !Validator::date($_GET['data_final'])
        ) {
            Response::error('Informe data_inicial e data_final válidas.', 422);
        }
        if ($_GET['data_inicial'] > $_GET['data_final']) {
            Response::error('A data inicial não pode ser maior que a data final.', 422);
        }

        $state = $store->read();
        $income = 0.0;
        $expenses = 0.0;
        foreach ($state['lancamentos'] as $transaction) {
            if ($transaction['data'] < $_GET['data_inicial'] || $transaction['data'] > $_GET['data_final']) {
                continue;
            }
            if ($transaction['tipo'] === 'receita') {
                $income += (float)$transaction['valor'];
            } else {
                $expenses += (float)$transaction['valor'];
            }
        }
        Response::json([
            'sucesso' => true,
            'data_inicial' => $_GET['data_inicial'],
            'data_final' => $_GET['data_final'],
            'total_receitas' => round($income, 2),
            'total_despesas' => round($expenses, 2),
        ]);
    }

    Response::error('Endpoint não encontrado ou método HTTP não permitido.', 404);
} catch (DomainException $exception) {
    Response::error($exception->getMessage(), $exception->getCode() ?: 422);
} catch (Throwable $exception) {
    error_log($exception->__toString());
    Response::error('Erro interno do servidor.', 500);
}
