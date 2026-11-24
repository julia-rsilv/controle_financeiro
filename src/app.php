<?php

// ============================================================================
// API DE CONTROLE FINANCEIRO PESSOAL (Slim + arquivos JSON)
//
// Este arquivo segue o modelo da aula:
//   1. Tudo fica em src/app.php e o arquivo termina com "return $app;".
//   2. Funções auxiliares são declaradas dentro de "if (!function_exists(...))".
//   3. Os dados ficam em arquivos JSON (sem banco de dados).
//   4. Os testes PHPUnit fazem "require" deste arquivo para obter o $app.
// ============================================================================

// "use" importa classes para podermos escrever só o nome curto delas.
use Psr\Http\Message\ResponseInterface as Response;        // representa a resposta HTTP
use Psr\Http\Message\ServerRequestInterface as Request;    // representa a requisição HTTP
use Psr\Http\Server\RequestHandlerInterface as Handler;    // usado no middleware
use Slim\Exception\HttpException;                          // erros HTTP do Slim (ex.: 404)
use Slim\Factory\AppFactory;                               // fábrica que cria o app Slim

// Carrega o autoload do Composer: é ele que permite usar o Slim.
require_once __DIR__ . '/../vendor/autoload.php';


// ============================================================================
// FUNÇÕES AUXILIARES
//
// Por que "function_exists"? Os testes carregam este arquivo várias vezes
// (um require por teste). Em PHP, declarar duas vezes a mesma função gera
// erro fatal. O "if (!function_exists(...))" garante que ela só seja criada
// na primeira vez.
// ============================================================================

// ---------- Dados iniciais -------------------------------------------------

if (!function_exists('categoriasIniciais')) {
    // Categorias gravadas no arquivo somente se ele ainda não existir.
    function categoriasIniciais(): array
    {
        return [
            ['id' => 1, 'nome' => 'Salário', 'tipo' => 'receita'],
            ['id' => 2, 'nome' => 'Moradia', 'tipo' => 'despesa'],
            ['id' => 3, 'nome' => 'Alimentação', 'tipo' => 'despesa'],
            ['id' => 4, 'nome' => 'Renda extra', 'tipo' => 'receita'],
            ['id' => 5, 'nome' => 'Contas', 'tipo' => 'despesa'],
        ];
    }
}

if (!function_exists('transacoesIniciais')) {
    // Lançamentos de exemplo, também gravados só na primeira execução.
    function transacoesIniciais(): array
    {
        return [
            ['id' => 1, 'descricao' => 'Salário', 'valor' => 3500.00, 'data' => '2026-11-05', 'tipo' => 'receita', 'categoria_id' => 1],
            ['id' => 2, 'descricao' => 'Aluguel', 'valor' => 1200.00, 'data' => '2026-11-06', 'tipo' => 'despesa', 'categoria_id' => 2],
            ['id' => 3, 'descricao' => 'Supermercado', 'valor' => 450.75, 'data' => '2026-11-08', 'tipo' => 'despesa', 'categoria_id' => 3],
            ['id' => 4, 'descricao' => 'Freelance', 'valor' => 800.00, 'data' => '2026-11-10', 'tipo' => 'receita', 'categoria_id' => 4],
            ['id' => 5, 'descricao' => 'Conta de energia', 'valor' => 180.40, 'data' => '2026-11-10', 'tipo' => 'despesa', 'categoria_id' => 5],
        ];
    }
}

// ---------- Acesso aos arquivos JSON --------------------------------------

if (!function_exists('diretorioDados')) {
    // Devolve a pasta onde os JSON ficam. A variável de ambiente
    // FINANCE_DATA_DIR permite que os testes usem uma pasta temporária,
    // sem mexer nos dados reais. Sem ela, usamos a pasta "data" ao lado deste arquivo.
    function diretorioDados(): string
    {
        $diretorio = getenv('FINANCE_DATA_DIR') ?: __DIR__ . '/data';

        // Cria a pasta se ela não existir (0777 = permissões; true = cria pastas intermediárias).
        // O "!is_dir" final cobre o caso de outro processo ter criado a pasta no mesmo instante.
        if (!is_dir($diretorio) && !mkdir($diretorio, 0777, true) && !is_dir($diretorio)) {
            throw new RuntimeException('Não foi possível criar o diretório de dados.');
        }

        return $diretorio;
    }
}

if (!function_exists('caminhoCategorias')) {
    // Caminho completo do arquivo de categorias.
    function caminhoCategorias(): string
    {
        return diretorioDados() . '/categorias.json';
    }
}

if (!function_exists('caminhoTransacoes')) {
    // Caminho completo do arquivo de lançamentos.
    function caminhoTransacoes(): string
    {
        return diretorioDados() . '/transacoes.json';
    }
}

if (!function_exists('salvarArquivo')) {
    // Converte um array em JSON e grava no arquivo indicado.
    function salvarArquivo(string $arquivo, array $dados): void
    {
        // PRETTY_PRINT = JSON indentado e legível;
        // UNESCAPED_UNICODE = mantém acentos (não vira \u00e7);
        // THROW_ON_ERROR = lança exceção se a conversão falhar.
        $json = json_encode(
            $dados,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        // LOCK_EX trava o arquivo durante a escrita, evitando gravações simultâneas.
        if (file_put_contents($arquivo, $json, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível salvar os dados.');
        }
    }
}

if (!function_exists('lerArquivo')) {
    // Lê o arquivo JSON e devolve como array do PHP.
    function lerArquivo(string $arquivo): array
    {
        $conteudo = file_get_contents($arquivo);

        if ($conteudo === false) {
            throw new RuntimeException('Não foi possível ler os dados.');
        }

        // true = devolve array (e não objeto); 512 = profundidade máxima;
        // THROW_ON_ERROR = lança exceção se o JSON estiver quebrado.
        $dados = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($dados)) {
            throw new RuntimeException('O arquivo de dados contém um formato inválido.');
        }

        return $dados;
    }
}

if (!function_exists('garantirArquivos')) {
    // Cria os arquivos com os dados iniciais caso ainda não existam.
    function garantirArquivos(): void
    {
        if (!is_file(caminhoCategorias())) {
            salvarArquivo(caminhoCategorias(), categoriasIniciais());
        }

        if (!is_file(caminhoTransacoes())) {
            salvarArquivo(caminhoTransacoes(), transacoesIniciais());
        }
    }
}

// Atalhos para ler e gravar cada arquivo, deixando as rotas mais limpas.
if (!function_exists('carregarCategorias')) {
    function carregarCategorias(): array
    {
        return lerArquivo(caminhoCategorias());
    }
}

if (!function_exists('carregarTransacoes')) {
    function carregarTransacoes(): array
    {
        return lerArquivo(caminhoTransacoes());
    }
}

if (!function_exists('salvarCategorias')) {
    function salvarCategorias(array $categorias): void
    {
        salvarArquivo(caminhoCategorias(), $categorias);
    }
}

if (!function_exists('salvarTransacoes')) {
    function salvarTransacoes(array $transacoes): void
    {
        salvarArquivo(caminhoTransacoes(), $transacoes);
    }
}

// ---------- Respostas JSON -------------------------------------------------

if (!function_exists('responderJson')) {
    // Escreve os dados como JSON no corpo da resposta e define status e cabeçalho.
    function responderJson(Response $response, mixed $dados, int $status): Response
    {
        $response->getBody()->write(json_encode(
            $dados,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8') // avisa que é JSON
            ->withStatus($status);                                           // código HTTP (200, 404...)
    }
}

if (!function_exists('responderErro')) {
    // Padroniza os erros: sempre {"erro": "mensagem"} e, se houver, {"campos": [...]}.
    function responderErro(Response $response, string $mensagem, int $status, array $campos = []): Response
    {
        $erro = ['erro' => $mensagem];

        if ($campos !== []) {
            $erro['campos'] = $campos; // lista dos campos com problema
        }

        return responderJson($response, $erro, $status);
    }
}

// ---------- Busca e identificadores ---------------------------------------

if (!function_exists('buscarIndice')) {
    // Procura um item pelo "id" e devolve a posição dele no array (ou null se não achar).
    function buscarIndice(array $itens, int $id): ?int
    {
        foreach ($itens as $indice => $item) {
            if (is_array($item) && isset($item['id']) && (int) $item['id'] === $id) {
                return $indice;
            }
        }

        return null;
    }
}

if (!function_exists('proximoId')) {
    // Calcula o próximo id: o maior existente + 1 (ou 1 se a lista estiver vazia).
    // Usar o maior id (e não a contagem) evita repetir ids depois de uma exclusão.
    function proximoId(array $itens): int
    {
        $ids = array_column($itens, 'id'); // extrai só a coluna "id"

        return $ids === [] ? 1 : max(array_map('intval', $ids)) + 1;
    }
}

if (!function_exists('validarIdentificador')) {
    // Converte o id da URL em inteiro positivo. Devolve null se for inválido (ex.: "abc" ou "-3").
    function validarIdentificador(string $valor): ?int
    {
        $id = filter_var($valor, FILTER_VALIDATE_INT);

        return $id !== false && $id > 0 ? $id : null;
    }
}

// ---------- Validações -----------------------------------------------------

if (!function_exists('validarData')) {
    // Confere se a data está no formato AAAA-MM-DD e se realmente existe.
    function validarData(mixed $valor): bool
    {
        if (!is_string($valor)) {
            return false;
        }

        // O "!" zera hora/minuto/segundo, deixando a comparação limpa.
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        $erros = DateTimeImmutable::getLastErrors();

        // O último teste (format === valor) barra datas como 2026-02-30,
        // que o PHP "corrigiria" sozinho para março.
        return $data !== false
            && ($erros === false || ($erros['warning_count'] === 0 && $erros['error_count'] === 0))
            && $data->format('Y-m-d') === $valor;
    }
}

if (!function_exists('validarCategoria')) {
    // Valida os dados de uma categoria. Devolve ['dados' => ...] se estiver tudo certo,
    // ou ['status' => ..., 'erro' => ..., 'campos' => ...] se houver problema.
    // $categoriaAtual é usado no PUT: a categoria pode manter o próprio nome.
    function validarCategoria(?array $body, array $categorias, ?int $categoriaAtual = null): array
    {
        $faltando = [];

        // 1) Campos obrigatórios: ausentes ou vazios geram erro 400.
        foreach (['nome', 'tipo'] as $campo) {
            if (!isset($body[$campo]) || $body[$campo] === '') {
                $faltando[] = $campo;
            }
        }

        if ($faltando !== []) {
            return ['status' => 400, 'erro' => 'Campos obrigatórios ausentes', 'campos' => $faltando];
        }

        // 2) Formato dos campos: erros de conteúdo geram 422.
        if (!is_string($body['nome']) || trim($body['nome']) === '') {
            return ['status' => 422, 'erro' => 'O nome deve ser um texto não vazio.', 'campos' => ['nome']];
        }

        // O "true" no in_array compara tipo estrito (evita 0 == "receita").
        if (!in_array($body['tipo'], ['receita', 'despesa'], true)) {
            return ['status' => 422, 'erro' => 'O tipo deve ser receita ou despesa.', 'campos' => ['tipo']];
        }

        // 3) Nome repetido gera 409 (conflito). Ignora maiúsculas/minúsculas e espaços nas pontas.
        foreach ($categorias as $categoria) {
            if (
                $categoria['id'] !== $categoriaAtual &&
                mb_strtolower(trim($categoria['nome'])) === mb_strtolower(trim($body['nome']))
            ) {
                return ['status' => 409, 'erro' => 'Já existe uma categoria com esse nome.', 'campos' => ['nome']];
            }
        }

        // Tudo certo: devolve os dados já limpos.
        return ['dados' => ['nome' => trim($body['nome']), 'tipo' => $body['tipo']]];
    }
}

if (!function_exists('validarTransacao')) {
    // Valida os dados de um lançamento e confere se a categoria existe e combina com o tipo.
    function validarTransacao(?array $body, array $categorias): array
    {
        $faltando = [];

        // 1) Campos obrigatórios (400).
        foreach (['descricao', 'valor', 'data', 'tipo', 'categoria_id'] as $campo) {
            if (!isset($body[$campo]) || $body[$campo] === '') {
                $faltando[] = $campo;
            }
        }

        if ($faltando !== []) {
            return ['status' => 400, 'erro' => 'Campos obrigatórios ausentes', 'campos' => $faltando];
        }

        // 2) Descrição: texto não vazio.
        if (!is_string($body['descricao']) || trim($body['descricao']) === '') {
            return ['status' => 422, 'erro' => 'A descrição deve ser um texto não vazio.', 'campos' => ['descricao']];
        }

        // 3) Valor: numérico, finito (não INF/NAN) e maior que zero.
        if (!is_numeric($body['valor']) || !is_finite((float) $body['valor']) || (float) $body['valor'] <= 0) {
            return ['status' => 422, 'erro' => 'O valor deve ser um número maior que zero.', 'campos' => ['valor']];
        }

        // 4) Data válida no formato AAAA-MM-DD.
        if (!validarData($body['data'])) {
            return ['status' => 422, 'erro' => 'A data deve ser válida e estar no formato AAAA-MM-DD.', 'campos' => ['data']];
        }

        // 5) Tipo: receita ou despesa.
        if (!in_array($body['tipo'], ['receita', 'despesa'], true)) {
            return ['status' => 422, 'erro' => 'O tipo deve ser receita ou despesa.', 'campos' => ['tipo']];
        }

        // 6) categoria_id: precisa ser um inteiro positivo.
        $categoriaId = filter_var($body['categoria_id'], FILTER_VALIDATE_INT);

        if ($categoriaId === false || $categoriaId < 1) {
            return ['status' => 422, 'erro' => 'A categoria informada é inválida.', 'campos' => ['categoria_id']];
        }

        // 7) A categoria precisa existir (404 se não existir).
        $indiceCategoria = buscarIndice($categorias, $categoriaId);

        if ($indiceCategoria === null) {
            return ['status' => 404, 'erro' => 'Categoria não encontrada.', 'campos' => ['categoria_id']];
        }

        // 8) O tipo do lançamento deve ser o mesmo da categoria
        //    (não se pode lançar uma despesa na categoria "Salário").
        if ($categorias[$indiceCategoria]['tipo'] !== $body['tipo']) {
            return ['status' => 422, 'erro' => 'O tipo do lançamento deve corresponder ao tipo da categoria.', 'campos' => ['tipo', 'categoria_id']];
        }

        // Tudo certo: devolve os dados já limpos e no formato certo.
        return [
            'dados' => [
                'descricao' => trim($body['descricao']),
                'valor' => round((float) $body['valor'], 2), // 2 casas decimais
                'data' => $body['data'],
                'tipo' => $body['tipo'],
                'categoria_id' => $categoriaId,
            ],
        ];
    }
}

// ---------- Cálculo dos resumos -------------------------------------------

if (!function_exists('calcularTotais')) {
    // Soma receitas e despesas de uma lista de lançamentos e calcula o saldo.
    // Usada pelas duas rotas de resumo, evitando repetir código.
    function calcularTotais(array $transacoes): array
    {
        $totais = ['receitas' => 0.0, 'despesas' => 0.0];

        foreach ($transacoes as $transacao) {
            // Se for receita soma em "receitas"; senão, em "despesas".
            $chave = $transacao['tipo'] === 'receita' ? 'receitas' : 'despesas';
            $totais[$chave] += (float) $transacao['valor'];
        }

        // round() evita resultados como 2569.8500000000004 (imprecisão de float).
        $totais['receitas'] = round($totais['receitas'], 2);
        $totais['despesas'] = round($totais['despesas'], 2);
        $totais['saldo'] = round($totais['receitas'] - $totais['despesas'], 2);

        return $totais;
    }
}


// ============================================================================
// CRIAÇÃO DO APLICATIVO
// ============================================================================

garantirArquivos();                    // cria os JSON iniciais, se necessário

$app = AppFactory::create();           // cria a aplicação Slim
$app->addBodyParsingMiddleware();      // transforma o JSON recebido em array (getParsedBody)


// ============================================================================
// ROTAS DE CATEGORIAS
// ============================================================================

// GET /categorias -> lista todas as categorias.
$app->get('/categorias', function (Request $request, Response $response) {
    return responderJson($response, carregarCategorias(), 200);
});

// GET /categorias/{id} -> busca uma categoria específica.
$app->get('/categorias/{id}', function (Request $request, Response $response, array $args) {
    $id = validarIdentificador($args['id']);   // {id} vem da URL como texto
    $categorias = carregarCategorias();
    $indice = $id === null ? null : buscarIndice($categorias, $id);

    if ($indice === null) {
        return responderErro($response, 'Categoria não encontrada.', 404);
    }

    return responderJson($response, $categorias[$indice], 200);
});

// POST /categorias -> cria uma categoria nova.
$app->post('/categorias', function (Request $request, Response $response) {
    $categorias = carregarCategorias();
    $body = $request->getParsedBody();         // corpo JSON já convertido em array

    // Se o corpo não for um array (ex.: JSON inválido), passamos null para a validação.
    $validacao = validarCategoria(is_array($body) ? $body : null, $categorias);

    // Se a validação devolveu "erro", respondemos com o status e a mensagem dela.
    if (isset($validacao['erro'])) {
        return responderErro($response, $validacao['erro'], $validacao['status'], $validacao['campos'] ?? []);
    }

    // O "..." (spread) copia os campos validados para dentro do novo array.
    $nova = ['id' => proximoId($categorias), ...$validacao['dados']];
    $categorias[] = $nova;                     // adiciona ao final da lista
    salvarCategorias($categorias);             // grava no arquivo

    return responderJson($response, $nova, 201); // 201 = criado
});

// PUT /categorias/{id} -> atualiza uma categoria existente.
$app->put('/categorias/{id}', function (Request $request, Response $response, array $args) {
    $id = validarIdentificador($args['id']);
    $categorias = carregarCategorias();
    $indice = $id === null ? null : buscarIndice($categorias, $id);

    if ($indice === null) {
        return responderErro($response, 'Categoria não encontrada.', 404);
    }

    $body = $request->getParsedBody();
    // Passamos $id para a categoria poder manter o próprio nome sem dar conflito.
    $validacao = validarCategoria(is_array($body) ? $body : null, $categorias, $id);

    if (isset($validacao['erro'])) {
        return responderErro($response, $validacao['erro'], $validacao['status'], $validacao['campos'] ?? []);
    }

    // Regra de negócio: não pode trocar o tipo de uma categoria que já tem lançamentos,
    // senão os lançamentos antigos ficariam com tipo diferente do da categoria.
    $temLancamentos = false;

    foreach (carregarTransacoes() as $transacao) {
        if ((int) $transacao['categoria_id'] === $id) {
            $temLancamentos = true;
            break; // já achou um, não precisa continuar
        }
    }

    if ($temLancamentos && $categorias[$indice]['tipo'] !== $validacao['dados']['tipo']) {
        return responderErro($response, 'Não é possível alterar o tipo de uma categoria que possui lançamentos.', 409);
    }

    $categorias[$indice] = ['id' => $id, ...$validacao['dados']]; // substitui mantendo o id
    salvarCategorias($categorias);

    return responderJson($response, $categorias[$indice], 200);
});

// DELETE /categorias/{id} -> exclui uma categoria.
$app->delete('/categorias/{id}', function (Request $request, Response $response, array $args) {
    $id = validarIdentificador($args['id']);
    $categorias = carregarCategorias();
    $indice = $id === null ? null : buscarIndice($categorias, $id);

    if ($indice === null) {
        return responderErro($response, 'Categoria não encontrada.', 404);
    }

    // Regra de negócio: não exclui categoria que ainda tem lançamentos.
    foreach (carregarTransacoes() as $transacao) {
        if ((int) $transacao['categoria_id'] === $id) {
            return responderErro($response, 'Não é possível excluir uma categoria que possui lançamentos.', 409);
        }
    }

    unset($categorias[$indice]);                     // remove o item
    salvarCategorias(array_values($categorias));     // array_values reorganiza os índices (0,1,2...)

    return responderJson($response, ['mensagem' => 'Categoria excluída com sucesso.'], 200);
});


// ============================================================================
// ROTAS DE LANÇAMENTOS
// ============================================================================

// GET /lancamentos -> lista lançamentos, com filtros opcionais na URL:
// ?data_inicio=...&data_fim=...&tipo=...&categoria_id=...
$app->get('/lancamentos', function (Request $request, Response $response) {
    $params = $request->getQueryParams();     // parâmetros da URL (após o "?")
    $inicio = $params['data_inicio'] ?? null; // "?? null" = usa null se não vier
    $fim = $params['data_fim'] ?? null;

    // Valida cada data informada (só valida se foi enviada).
    foreach (['data_inicio' => $inicio, 'data_fim' => $fim] as $campo => $data) {
        if ($data !== null && !validarData($data)) {
            return responderErro($response, "O campo {$campo} deve ser uma data válida no formato AAAA-MM-DD.", 422, [$campo]);
        }
    }

    // Datas AAAA-MM-DD podem ser comparadas como texto, em ordem cronológica.
    if ($inicio !== null && $fim !== null && $inicio > $fim) {
        return responderErro($response, 'A data inicial não pode ser posterior à data final.', 422, ['data_inicio', 'data_fim']);
    }

    $tipo = $params['tipo'] ?? null;

    if ($tipo !== null && !in_array($tipo, ['receita', 'despesa'], true)) {
        return responderErro($response, 'O tipo deve ser receita ou despesa.', 422, ['tipo']);
    }

    $categoriaId = null;

    if (isset($params['categoria_id'])) {
        $categoriaId = validarIdentificador((string) $params['categoria_id']);

        if ($categoriaId === null) {
            return responderErro($response, 'A categoria informada é inválida.', 422, ['categoria_id']);
        }

        if (buscarIndice(carregarCategorias(), $categoriaId) === null) {
            return responderErro($response, 'Categoria não encontrada.', 404, ['categoria_id']);
        }
    }

    // array_filter mantém só os lançamentos que passam em todos os filtros ativos.
    // Filtro não informado (null) é ignorado. array_values reorganiza os índices.
    $filtradas = array_values(array_filter(
        carregarTransacoes(),
        static function (array $transacao) use ($inicio, $fim, $tipo, $categoriaId): bool {
            return ($inicio === null || $transacao['data'] >= $inicio)
                && ($fim === null || $transacao['data'] <= $fim)
                && ($tipo === null || $transacao['tipo'] === $tipo)
                && ($categoriaId === null || (int) $transacao['categoria_id'] === $categoriaId);
        }
    ));

    return responderJson($response, $filtradas, 200);
});

// GET /lancamentos/{id} -> busca um lançamento específico.
$app->get('/lancamentos/{id}', function (Request $request, Response $response, array $args) {
    $id = validarIdentificador($args['id']);
    $transacoes = carregarTransacoes();
    $indice = $id === null ? null : buscarIndice($transacoes, $id);

    if ($indice === null) {
        return responderErro($response, 'Lançamento não encontrado.', 404);
    }

    return responderJson($response, $transacoes[$indice], 200);
});

// POST /lancamentos -> cria um lançamento novo.
$app->post('/lancamentos', function (Request $request, Response $response) {
    $body = $request->getParsedBody();
    $validacao = validarTransacao(is_array($body) ? $body : null, carregarCategorias());

    if (isset($validacao['erro'])) {
        return responderErro($response, $validacao['erro'], $validacao['status'], $validacao['campos'] ?? []);
    }

    $transacoes = carregarTransacoes();
    $novo = ['id' => proximoId($transacoes), ...$validacao['dados']];
    $transacoes[] = $novo;
    salvarTransacoes($transacoes);

    return responderJson($response, $novo, 201);
});

// PUT /lancamentos/{id} -> atualiza um lançamento existente.
$app->put('/lancamentos/{id}', function (Request $request, Response $response, array $args) {
    $id = validarIdentificador($args['id']);
    $transacoes = carregarTransacoes();
    $indice = $id === null ? null : buscarIndice($transacoes, $id);

    if ($indice === null) {
        return responderErro($response, 'Lançamento não encontrado.', 404);
    }

    $body = $request->getParsedBody();
    $validacao = validarTransacao(is_array($body) ? $body : null, carregarCategorias());

    if (isset($validacao['erro'])) {
        return responderErro($response, $validacao['erro'], $validacao['status'], $validacao['campos'] ?? []);
    }

    $transacoes[$indice] = ['id' => $id, ...$validacao['dados']]; // substitui mantendo o id
    salvarTransacoes($transacoes);

    return responderJson($response, $transacoes[$indice], 200);
});

// DELETE /lancamentos/{id} -> exclui um lançamento.
$app->delete('/lancamentos/{id}', function (Request $request, Response $response, array $args) {
    $id = validarIdentificador($args['id']);
    $transacoes = carregarTransacoes();
    $indice = $id === null ? null : buscarIndice($transacoes, $id);

    if ($indice === null) {
        return responderErro($response, 'Lançamento não encontrado.', 404);
    }

    unset($transacoes[$indice]);
    salvarTransacoes(array_values($transacoes));

    return responderJson($response, ['mensagem' => 'Lançamento excluído com sucesso.'], 200);
});


// ============================================================================
// ROTAS DE RESUMO FINANCEIRO
// ============================================================================

// GET /resumo/saldo -> total de receitas, despesas e saldo de todos os lançamentos.
$app->get('/resumo/saldo', function (Request $request, Response $response) {
    return responderJson($response, calcularTotais(carregarTransacoes()), 200);
});

// GET /resumo/periodo?data_inicio=...&data_fim=... -> mesmo cálculo, só dentro do período.
$app->get('/resumo/periodo', function (Request $request, Response $response) {
    $params = $request->getQueryParams();
    $inicio = $params['data_inicio'] ?? null;
    $fim = $params['data_fim'] ?? null;
    $faltando = [];

    // Aqui as duas datas são obrigatórias.
    if ($inicio === null || $inicio === '') {
        $faltando[] = 'data_inicio';
    }

    if ($fim === null || $fim === '') {
        $faltando[] = 'data_fim';
    }

    if ($faltando !== []) {
        return responderErro($response, 'Informe a data inicial e a data final.', 400, $faltando);
    }

    if (!validarData($inicio) || !validarData($fim)) {
        return responderErro($response, 'As datas devem ser válidas e estar no formato AAAA-MM-DD.', 422, ['data_inicio', 'data_fim']);
    }

    if ($inicio > $fim) {
        return responderErro($response, 'A data inicial não pode ser posterior à data final.', 422, ['data_inicio', 'data_fim']);
    }

    // Seleciona só os lançamentos dentro do período (as duas datas entram: >= e <=).
    $noPeriodo = array_filter(
        carregarTransacoes(),
        static fn (array $transacao): bool => $transacao['data'] >= $inicio && $transacao['data'] <= $fim
    );

    $totais = calcularTotais($noPeriodo);
    $totais['data_inicio'] = $inicio;   // devolve o período consultado junto com os totais
    $totais['data_fim'] = $fim;

    return responderJson($response, $totais, 200);
});


// ============================================================================
// MIDDLEWARES (executam antes/depois das rotas)
// ============================================================================

// Middleware: nas operações de escrita (POST/PUT/PATCH), exige Content-Type JSON.
$app->add(function (Request $request, Handler $handler) use ($app): Response {
    // O cabeçalho pode vir como "application/json; charset=utf-8".
    // Pegamos só a parte antes do ";" e deixamos em minúsculas.
    $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));

    if (
        in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH'], true) &&
        $contentType !== 'application/json'
    ) {
        return responderErro(
            $app->getResponseFactory()->createResponse(), // cria uma resposta vazia
            'O Content-Type da requisição deve ser application/json.',
            400
        );
    }

    // Tudo certo: passa a requisição adiante (para a próxima camada ou a rota).
    return $handler->handle($request);
});

// Middleware de erros do Slim: (detalhes na tela = false, registrar log = true, detalhes no log = true).
// Detalhes na tela ficam desligados para não expor informações internas.
$errorMiddleware = $app->addErrorMiddleware(false, true, true);

// Handler padrão: toda exceção não tratada vira uma resposta JSON padronizada.
$errorMiddleware->setDefaultErrorHandler(
    function (
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($app): Response {
        // Erros HTTP do Slim (ex.: rota inexistente) trazem o código no próprio objeto;
        // qualquer outro erro vira 500.
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        $status = $status >= 400 && $status <= 599 ? $status : 500; // garante um código válido

        // Erros 500 vão para o log do servidor (o usuário não vê o detalhe).
        if ($status >= 500 && $logErrors) {
            error_log((string) $exception);
        }

        $mensagem = $status === 404
            ? 'Endpoint não encontrado.'
            : 'Ocorreu um erro interno.';

        return responderJson(
            $app->getResponseFactory()->createResponse(),
            ['erro' => $mensagem],
            $status
        );
    }
);

// Devolve o app: o public/index.php chama $app->run() e os testes usam $app->handle().
return $app;
