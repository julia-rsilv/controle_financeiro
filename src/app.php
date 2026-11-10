<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$categoriasIniciais = [
    ['id' => 1, 'nome' => 'Salário', 'tipo' => 'receita'],
    ['id' => 2, 'nome' => 'Moradia', 'tipo' => 'despesa'],
    ['id' => 3, 'nome' => 'Alimentação', 'tipo' => 'despesa'],
    ['id' => 4, 'nome' => 'Renda extra', 'tipo' => 'receita'],
    ['id' => 5, 'nome' => 'Contas', 'tipo' => 'despesa'],
];

$transacoesIniciais = [
    [
        'id' => 1,
        'descricao' => 'Salário',
        'valor' => 3500.00,
        'data' => '2026-11-05',
        'tipo' => 'receita',
        'categoria_id' => 1,
    ],
    [
        'id' => 2,
        'descricao' => 'Aluguel',
        'valor' => 1200.00,
        'data' => '2026-11-06',
        'tipo' => 'despesa',
        'categoria_id' => 2,
    ],
    [
        'id' => 3,
        'descricao' => 'Supermercado',
        'valor' => 450.75,
        'data' => '2026-11-08',
        'tipo' => 'despesa',
        'categoria_id' => 3,
    ],
    [
        'id' => 4,
        'descricao' => 'Freelance',
        'valor' => 800.00,
        'data' => '2026-11-10',
        'tipo' => 'receita',
        'categoria_id' => 4,
    ],
    [
        'id' => 5,
        'descricao' => 'Conta de energia',
        'valor' => 180.40,
        'data' => '2026-11-10',
        'tipo' => 'despesa',
        'categoria_id' => 5,
    ],
];

$diretorioDados = getenv('FINANCE_DATA_DIR') ?: __DIR__ . '/data';

if (!is_dir($diretorioDados) && !mkdir($diretorioDados, 0777, true) && !is_dir($diretorioDados)) {
    throw new RuntimeException('Não foi possível criar o diretório de dados.');
}

$arquivoCategorias = $diretorioDados . '/categorias.json';
$arquivoTransacoes = $diretorioDados . '/transacoes.json';

$salvarArquivo = static function (string $arquivo, array $dados): void {
    $json = json_encode(
        $dados,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    if (file_put_contents($arquivo, $json, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível salvar os dados.');
    }
};

$lerArquivo = static function (string $arquivo): array {
    $conteudo = file_get_contents($arquivo);

    if ($conteudo === false) {
        throw new RuntimeException('Não foi possível ler os dados.');
    }

    $dados = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($dados)) {
        throw new RuntimeException('O arquivo de dados contém um formato inválido.');
    }

    return $dados;
};

if (!is_file($arquivoCategorias)) {
    $salvarArquivo($arquivoCategorias, $categoriasIniciais);
}

if (!is_file($arquivoTransacoes)) {
    $salvarArquivo($arquivoTransacoes, $transacoesIniciais);
} else {
    $transacoesExistentes = $lerArquivo($arquivoTransacoes);
    $categoriasExistentes = $lerArquivo($arquivoCategorias);
    $migracaoNecessaria = false;

    foreach ($transacoesExistentes as &$transacao) {
        if (!is_array($transacao) || isset($transacao['categoria_id'])) {
            continue;
        }

        if (!isset($transacao['categoria']) || !is_string($transacao['categoria'])) {
            continue;
        }

        $nomeCategoria = trim($transacao['categoria']);
        $categoriaId = null;

        foreach ($categoriasExistentes as $categoria) {
            if (
                is_array($categoria) &&
                isset($categoria['nome'], $categoria['id']) &&
                mb_strtolower($categoria['nome']) === mb_strtolower($nomeCategoria)
            ) {
                $categoriaId = (int) $categoria['id'];
                break;
            }
        }

        if ($categoriaId === null) {
            $ids = array_column($categoriasExistentes, 'id');
            $categoriaId = $ids ? max(array_map('intval', $ids)) + 1 : 1;
            $tipo = in_array($transacao['tipo'] ?? null, ['receita', 'despesa'], true)
                ? $transacao['tipo']
                : 'despesa';
            $categoriasExistentes[] = [
                'id' => $categoriaId,
                'nome' => $nomeCategoria,
                'tipo' => $tipo,
            ];
        }

        $transacao['categoria_id'] = $categoriaId;
        unset($transacao['categoria']);
        $migracaoNecessaria = true;
    }
    unset($transacao);

    if ($migracaoNecessaria) {
        $salvarArquivo($arquivoCategorias, $categoriasExistentes);
        $salvarArquivo($arquivoTransacoes, $transacoesExistentes);
    }
}

$carregarCategorias = static fn (): array => $lerArquivo($arquivoCategorias);
$carregarTransacoes = static fn (): array => $lerArquivo($arquivoTransacoes);
$salvarCategorias = static fn (array $categorias): mixed => $salvarArquivo($arquivoCategorias, $categorias);
$salvarTransacoes = static fn (array $transacoes): mixed => $salvarArquivo($arquivoTransacoes, $transacoes);

$responderJson = static function (
    Response $response,
    mixed $dados,
    int $status
): Response {
    $response->getBody()->write(json_encode(
        $dados,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ));

    return $response
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        ->withStatus($status);
};

$responderErro = static function (
    Response $response,
    string $mensagem,
    int $status,
    array $campos = []
) use ($responderJson): Response {
    $erro = ['erro' => $mensagem];

    if ($campos !== []) {
        $erro['campos'] = $campos;
    }

    return $responderJson($response, $erro, $status);
};

$buscarIndice = static function (array $itens, int $id): ?int {
    foreach ($itens as $indice => $item) {
        if (is_array($item) && isset($item['id']) && (int) $item['id'] === $id) {
            return $indice;
        }
    }

    return null;
};

$validarData = static function (mixed $valor): bool {
    if (!is_string($valor)) {
        return false;
    }

    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    $erros = DateTimeImmutable::getLastErrors();

    return $data !== false
        && ($erros === false || ($erros['warning_count'] === 0 && $erros['error_count'] === 0))
        && $data->format('Y-m-d') === $valor;
};

$validarCategoria = static function (
    ?array $body,
    array $categorias,
    ?int $categoriaAtual = null
): array {
    $campos = ['nome', 'tipo'];
    $faltando = [];

    foreach ($campos as $campo) {
        if (!isset($body[$campo]) || $body[$campo] === '') {
            $faltando[] = $campo;
        }
    }

    if ($faltando !== []) {
        return [
            'status' => 400,
            'erro' => 'Campos obrigatórios ausentes',
            'campos' => $faltando,
        ];
    }

    if (!is_string($body['nome']) || trim($body['nome']) === '') {
        return ['status' => 422, 'erro' => 'O nome deve ser um texto não vazio.', 'campos' => ['nome']];
    }

    if (!in_array($body['tipo'], ['receita', 'despesa'], true)) {
        return ['status' => 422, 'erro' => 'O tipo deve ser receita ou despesa.', 'campos' => ['tipo']];
    }

    foreach ($categorias as $categoria) {
        if (
            $categoria['id'] !== $categoriaAtual &&
            mb_strtolower(trim($categoria['nome'])) === mb_strtolower(trim($body['nome']))
        ) {
            return ['status' => 409, 'erro' => 'Já existe uma categoria com esse nome.', 'campos' => ['nome']];
        }
    }

    return [
        'dados' => [
            'nome' => trim($body['nome']),
            'tipo' => $body['tipo'],
        ],
    ];
};

$validarTransacao = static function (
    ?array $body,
    array $categorias,
) use ($buscarIndice, $validarData): array {
    $campos = ['descricao', 'valor', 'data', 'tipo', 'categoria_id'];
    $faltando = [];

    foreach ($campos as $campo) {
        if (!isset($body[$campo]) || $body[$campo] === '') {
            $faltando[] = $campo;
        }
    }

    if ($faltando !== []) {
        return [
            'status' => 400,
            'erro' => 'Campos obrigatórios ausentes',
            'campos' => $faltando,
        ];
    }

    if (!is_string($body['descricao']) || trim($body['descricao']) === '') {
        return ['status' => 422, 'erro' => 'A descrição deve ser um texto não vazio.', 'campos' => ['descricao']];
    }

    if (
        !is_numeric($body['valor']) ||
        !is_finite((float) $body['valor']) ||
        (float) $body['valor'] <= 0
    ) {
        return ['status' => 422, 'erro' => 'O valor deve ser um número maior que zero.', 'campos' => ['valor']];
    }

    if (!$validarData($body['data'])) {
        return ['status' => 422, 'erro' => 'A data deve ser válida e estar no formato AAAA-MM-DD.', 'campos' => ['data']];
    }

    if (!in_array($body['tipo'], ['receita', 'despesa'], true)) {
        return ['status' => 422, 'erro' => 'O tipo deve ser receita ou despesa.', 'campos' => ['tipo']];
    }

    $categoriaId = filter_var($body['categoria_id'], FILTER_VALIDATE_INT);

    if ($categoriaId === false || $categoriaId < 1) {
        return ['status' => 422, 'erro' => 'A categoria informada é inválida.', 'campos' => ['categoria_id']];
    }

    $indiceCategoria = $buscarIndice($categorias, $categoriaId);

    if ($indiceCategoria === null) {
        return ['status' => 404, 'erro' => 'Categoria não encontrada.', 'campos' => ['categoria_id']];
    }

    if ($categorias[$indiceCategoria]['tipo'] !== $body['tipo']) {
        return ['status' => 422, 'erro' => 'O tipo do lançamento deve corresponder ao tipo da categoria.', 'campos' => ['tipo', 'categoria_id']];
    }

    return [
        'dados' => [
            'descricao' => trim($body['descricao']),
            'valor' => round((float) $body['valor'], 2),
            'data' => $body['data'],
            'tipo' => $body['tipo'],
            'categoria_id' => $categoriaId,
        ],
    ];
};

$proximoId = static function (array $itens): int {
    $ids = array_column($itens, 'id');

    return $ids === [] ? 1 : max(array_map('intval', $ids)) + 1;
};

$validarIdentificador = static function (string $valor): ?int {
    $id = filter_var($valor, FILTER_VALIDATE_INT);

    return $id !== false && $id > 0 ? $id : null;
};

$app->get('/categorias', function (Request $request, Response $response) use ($carregarCategorias, $responderJson) {
    return $responderJson($response, $carregarCategorias(), 200);
});

$app->get('/categorias/{id}', function (Request $request, Response $response, array $args) use ($carregarCategorias, $buscarIndice, $validarIdentificador, $responderErro, $responderJson) {
    $id = $validarIdentificador($args['id']);
    $categorias = $carregarCategorias();
    $indice = $id === null ? null : $buscarIndice($categorias, $id);

    if ($indice === null) {
        return $responderErro($response, 'Categoria não encontrada.', 404);
    }

    return $responderJson($response, $categorias[$indice], 200);
});

$app->post('/categorias', function (Request $request, Response $response) use ($carregarCategorias, $salvarCategorias, $validarCategoria, $proximoId, $responderErro, $responderJson) {
    $categorias = $carregarCategorias();
    $validacao = $validarCategoria(
        is_array($request->getParsedBody()) ? $request->getParsedBody() : null,
        $categorias
    );

    if (isset($validacao['erro'])) {
        return $responderErro($response, $validacao['erro'], $validacao['status'], $validacao['campos'] ?? []);
    }

    $nova = ['id' => $proximoId($categorias), ...$validacao['dados']];
    $categorias[] = $nova;
    $salvarCategorias($categorias);

    return $responderJson($response, $nova, 201);
});

$app->put('/categorias/{id}', function (Request $request, Response $response, array $args) use ($carregarCategorias, $carregarTransacoes, $salvarCategorias, $buscarIndice, $validarIdentificador, $validarCategoria, $responderErro, $responderJson) {
    $id = $validarIdentificador($args['id']);
    $categorias = $carregarCategorias();
    $indice = $id === null ? null : $buscarIndice($categorias, $id);

    if ($indice === null) {
        return $responderErro($response, 'Categoria não encontrada.', 404);
    }

    $validacao = $validarCategoria(
        is_array($request->getParsedBody()) ? $request->getParsedBody() : null,
        $categorias,
        $id
    );

    if (isset($validacao['erro'])) {
        return $responderErro($response, $validacao['erro'], $validacao['status'], $validacao['campos'] ?? []);
    }

    $temLancamentos = false;

    foreach ($carregarTransacoes() as $transacao) {
        if ((int) $transacao['categoria_id'] === $id) {
            $temLancamentos = true;
            break;
        }
    }

    if ($temLancamentos && $categorias[$indice]['tipo'] !== $validacao['dados']['tipo']) {
        return $responderErro($response, 'Não é possível alterar o tipo de uma categoria que possui lançamentos.', 409);
    }

    $categorias[$indice] = [
        'id' => $id,
        ...$validacao['dados'],
    ];
    $salvarCategorias($categorias);

    return $responderJson($response, $categorias[$indice], 200);
});

$app->delete('/categorias/{id}', function (Request $request, Response $response, array $args) use ($carregarCategorias, $carregarTransacoes, $salvarCategorias, $buscarIndice, $validarIdentificador, $responderErro, $responderJson) {
    $id = $validarIdentificador($args['id']);
    $categorias = $carregarCategorias();
    $indice = $id === null ? null : $buscarIndice($categorias, $id);

    if ($indice === null) {
        return $responderErro($response, 'Categoria não encontrada.', 404);
    }

    foreach ($carregarTransacoes() as $transacao) {
        if ((int) $transacao['categoria_id'] === $id) {
            return $responderErro($response, 'Não é possível excluir uma categoria que possui lançamentos.', 409);
        }
    }

    unset($categorias[$indice]);
    $salvarCategorias(array_values($categorias));

    return $responderJson($response, ['mensagem' => 'Categoria excluída com sucesso.'], 200);
});

$app->get('/lancamentos', function (Request $request, Response $response) use ($carregarTransacoes, $carregarCategorias, $validarData, $validarIdentificador, $buscarIndice, $responderErro, $responderJson) {
    $params = $request->getQueryParams();
    $inicio = $params['data_inicio'] ?? null;
    $fim = $params['data_fim'] ?? null;

    foreach (['data_inicio' => $inicio, 'data_fim' => $fim] as $campo => $data) {
        if ($data !== null && !$validarData($data)) {
            return $responderErro($response, "O campo {$campo} deve ser uma data válida no formato AAAA-MM-DD.", 422, [$campo]);
        }
    }

    if ($inicio !== null && $fim !== null && $inicio > $fim) {
        return $responderErro($response, 'A data inicial não pode ser posterior à data final.', 422, ['data_inicio', 'data_fim']);
    }

    $tipo = $params['tipo'] ?? null;

    if ($tipo !== null && !in_array($tipo, ['receita', 'despesa'], true)) {
        return $responderErro($response, 'O tipo deve ser receita ou despesa.', 422, ['tipo']);
    }

    $categoriaId = null;

    if (isset($params['categoria_id'])) {
        $categoriaId = $validarIdentificador((string) $params['categoria_id']);

        if ($categoriaId === null) {
            return $responderErro($response, 'A categoria informada é inválida.', 422, ['categoria_id']);
        }

        if ($buscarIndice($carregarCategorias(), $categoriaId) === null) {
            return $responderErro($response, 'Categoria não encontrada.', 404, ['categoria_id']);
        }
    }

    $filtradas = array_values(array_filter(
        $carregarTransacoes(),
        static function (array $transacao) use ($inicio, $fim, $tipo, $categoriaId): bool {
            return ($inicio === null || $transacao['data'] >= $inicio)
                && ($fim === null || $transacao['data'] <= $fim)
                && ($tipo === null || $transacao['tipo'] === $tipo)
                && ($categoriaId === null || (int) $transacao['categoria_id'] === $categoriaId);
        }
    ));

    return $responderJson($response, $filtradas, 200);
});

$app->get('/lancamentos/{id}', function (Request $request, Response $response, array $args) use ($carregarTransacoes, $buscarIndice, $validarIdentificador, $responderErro, $responderJson) {
    $id = $validarIdentificador($args['id']);
    $transacoes = $carregarTransacoes();
    $indice = $id === null ? null : $buscarIndice($transacoes, $id);

    if ($indice === null) {
        return $responderErro($response, 'Lançamento não encontrado.', 404);
    }

    return $responderJson($response, $transacoes[$indice], 200);
});

$app->post('/lancamentos', function (Request $request, Response $response) use ($carregarTransacoes, $carregarCategorias, $salvarTransacoes, $validarTransacao, $proximoId, $responderErro, $responderJson) {
    $body = $request->getParsedBody();
    $validacao = $validarTransacao(
        is_array($body) ? $body : null,
        $carregarCategorias()
    );

    if (isset($validacao['erro'])) {
        return $responderErro($response, $validacao['erro'], $validacao['status'], $validacao['campos'] ?? []);
    }

    $transacoes = $carregarTransacoes();
    $novo = ['id' => $proximoId($transacoes), ...$validacao['dados']];
    $transacoes[] = $novo;
    $salvarTransacoes($transacoes);

    return $responderJson($response, $novo, 201);
});

$app->put('/lancamentos/{id}', function (Request $request, Response $response, array $args) use ($carregarTransacoes, $carregarCategorias, $salvarTransacoes, $buscarIndice, $validarIdentificador, $validarTransacao, $responderErro, $responderJson) {
    $id = $validarIdentificador($args['id']);
    $transacoes = $carregarTransacoes();
    $indice = $id === null ? null : $buscarIndice($transacoes, $id);

    if ($indice === null) {
        return $responderErro($response, 'Lançamento não encontrado.', 404);
    }

    $body = $request->getParsedBody();
    $validacao = $validarTransacao(
        is_array($body) ? $body : null,
        $carregarCategorias()
    );

    if (isset($validacao['erro'])) {
        return $responderErro($response, $validacao['erro'], $validacao['status'], $validacao['campos'] ?? []);
    }

    $transacoes[$indice] = ['id' => $id, ...$validacao['dados']];
    $salvarTransacoes($transacoes);

    return $responderJson($response, $transacoes[$indice], 200);
});

$app->delete('/lancamentos/{id}', function (Request $request, Response $response, array $args) use ($carregarTransacoes, $salvarTransacoes, $buscarIndice, $validarIdentificador, $responderErro, $responderJson) {
    $id = $validarIdentificador($args['id']);
    $transacoes = $carregarTransacoes();
    $indice = $id === null ? null : $buscarIndice($transacoes, $id);

    if ($indice === null) {
        return $responderErro($response, 'Lançamento não encontrado.', 404);
    }

    unset($transacoes[$indice]);
    $salvarTransacoes(array_values($transacoes));

    return $responderJson($response, ['mensagem' => 'Lançamento excluído com sucesso.'], 200);
});

$app->get('/resumo/saldo', function (Request $request, Response $response) use ($carregarTransacoes, $responderJson) {
    $totais = ['receitas' => 0.0, 'despesas' => 0.0];

    foreach ($carregarTransacoes() as $transacao) {
        $chave = $transacao['tipo'] === 'receita' ? 'receitas' : 'despesas';
        $totais[$chave] += (float) $transacao['valor'];
    }

    $totais['receitas'] = round($totais['receitas'], 2);
    $totais['despesas'] = round($totais['despesas'], 2);
    $totais['saldo'] = round($totais['receitas'] - $totais['despesas'], 2);

    return $responderJson($response, $totais, 200);
});

$app->get('/resumo/periodo', function (Request $request, Response $response) use ($carregarTransacoes, $validarData, $responderErro, $responderJson) {
    $params = $request->getQueryParams();
    $inicio = $params['data_inicio'] ?? null;
    $fim = $params['data_fim'] ?? null;
    $faltando = [];

    if ($inicio === null || $inicio === '') {
        $faltando[] = 'data_inicio';
    }

    if ($fim === null || $fim === '') {
        $faltando[] = 'data_fim';
    }

    if ($faltando !== []) {
        return $responderErro($response, 'Informe a data inicial e a data final.', 400, $faltando);
    }

    if (!$validarData($inicio) || !$validarData($fim)) {
        return $responderErro($response, 'As datas devem ser válidas e estar no formato AAAA-MM-DD.', 422, ['data_inicio', 'data_fim']);
    }

    if ($inicio > $fim) {
        return $responderErro($response, 'A data inicial não pode ser posterior à data final.', 422, ['data_inicio', 'data_fim']);
    }

    $totais = ['receitas' => 0.0, 'despesas' => 0.0];

    foreach ($carregarTransacoes() as $transacao) {
        if ($transacao['data'] < $inicio || $transacao['data'] > $fim) {
            continue;
        }

        $chave = $transacao['tipo'] === 'receita' ? 'receitas' : 'despesas';
        $totais[$chave] += (float) $transacao['valor'];
    }

    $totais['receitas'] = round($totais['receitas'], 2);
    $totais['despesas'] = round($totais['despesas'], 2);
    $totais['saldo'] = round($totais['receitas'] - $totais['despesas'], 2);
    $totais['data_inicio'] = $inicio;
    $totais['data_fim'] = $fim;

    return $responderJson($response, $totais, 200);
});

$app->add(function (
    Request $request,
    \Psr\Http\Server\RequestHandlerInterface $handler
) use ($app, $responderErro): Response {
    $contentType = strtolower(trim(explode(
        ';',
        $request->getHeaderLine('Content-Type'),
        2
    )[0]));

    if (
        in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH'], true) &&
        $contentType !== 'application/json'
    ) {
        return $responderErro(
            $app->getResponseFactory()->createResponse(),
            'O Content-Type da requisição deve ser application/json.',
            400
        );
    }

    return $handler->handle($request);
});

$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setDefaultErrorHandler(
    function (
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($app, $responderJson): Response {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        $status = $status >= 400 && $status <= 599 ? $status : 500;

        if ($status >= 500 && $logErrors) {
            error_log((string) $exception);
        }

        $mensagem = $status === 404
            ? 'Endpoint não encontrado.'
            : 'Ocorreu um erro interno.';

        return $responderJson(
            $app->getResponseFactory()->createResponse(),
            ['erro' => $mensagem],
            $status
        );
    }
);

return $app;
