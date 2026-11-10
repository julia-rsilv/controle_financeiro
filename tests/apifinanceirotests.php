<?php

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiFinanceiroTests extends TestCase
{
    private App $app;

    private string $diretorioTemporario;

    private string|false $diretorioAnterior;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diretorioAnterior = getenv('FINANCE_DATA_DIR');
        $this->diretorioTemporario = sys_get_temp_dir()
            . '/controle-financeiro-testes-'
            . bin2hex(random_bytes(8));

        if (!mkdir($this->diretorioTemporario, 0777, true)) {
            self::fail('Não foi possível criar o diretório temporário dos testes.');
        }

        putenv('FINANCE_DATA_DIR=' . $this->diretorioTemporario);
        $this->app = require __DIR__ . '/../src/app.php';
    }

    protected function tearDown(): void
    {
        foreach (['categorias.json', 'transacoes.json'] as $arquivo) {
            $caminho = $this->diretorioTemporario . '/' . $arquivo;

            if (is_file($caminho)) {
                unlink($caminho);
            }
        }

        if (is_dir($this->diretorioTemporario)) {
            rmdir($this->diretorioTemporario);
        }

        if ($this->diretorioAnterior === false) {
            putenv('FINANCE_DATA_DIR');
        } else {
            putenv('FINANCE_DATA_DIR=' . $this->diretorioAnterior);
        }

        parent::tearDown();
    }

    public function testListaCategorias(): void
    {
        $response = $this->enviar('GET', '/categorias');
        $categorias = $this->decodificar($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(5, $categorias);
        self::assertSame(
            ['id' => 1, 'nome' => 'Salário', 'tipo' => 'receita'],
            $categorias[0]
        );
    }

    public function testConsultaCategoriaExistente(): void
    {
        $response = $this->enviar('GET', '/categorias/2');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['id' => 2, 'nome' => 'Moradia', 'tipo' => 'despesa'],
            $this->decodificar($response)
        );
    }

    public function testConsultaCategoriaInexistente(): void
    {
        $this->assertErro(
            $this->enviar('GET', '/categorias/999'),
            404,
            'Categoria não encontrada.'
        );
    }

    public function testCadastraCategoria(): void
    {
        $response = $this->enviar('POST', '/categorias', [
            'nome' => 'Saúde',
            'tipo' => 'despesa',
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['id' => 6, 'nome' => 'Saúde', 'tipo' => 'despesa'],
            $this->decodificar($response)
        );
    }

    public function testCategoriaSemCamposObrigatoriosRetorna400(): void
    {
        $response = $this->enviar('POST', '/categorias', ['nome' => 'Saúde']);

        $this->assertErro(
            $response,
            400,
            'Campos obrigatórios ausentes',
            ['tipo']
        );
    }

    public function testCategoriaDuplicadaRetorna409(): void
    {
        $response = $this->enviar('POST', '/categorias', [
            'nome' => '  salário ',
            'tipo' => 'receita',
        ]);

        $this->assertErro(
            $response,
            409,
            'Já existe uma categoria com esse nome.',
            ['nome']
        );
    }

    public function testAtualizaCategoria(): void
    {
        $response = $this->enviar('PUT', '/categorias/2', [
            'nome' => 'Casa',
            'tipo' => 'despesa',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['id' => 2, 'nome' => 'Casa', 'tipo' => 'despesa'],
            $this->decodificar($response)
        );
    }

    public function testAtualizacaoDeCategoriaComDadosInvalidos(): void
    {
        $response = $this->enviar('PUT', '/categorias/2', [
            'nome' => 'Casa',
            'tipo' => 'investimento',
        ]);

        $this->assertErro(
            $response,
            422,
            'O tipo deve ser receita ou despesa.',
            ['tipo']
        );
    }

    public function testNaoPermiteAtualizarCategoriaComNomeDuplicado(): void
    {
        $this->assertErro(
            $this->enviar('PUT', '/categorias/2', [
                'nome' => 'Salário',
                'tipo' => 'despesa',
            ]),
            409,
            'Já existe uma categoria com esse nome.',
            ['nome']
        );
    }

    public function testAtualizacaoDeCategoriaInexistente(): void
    {
        $this->assertErro(
            $this->enviar('PUT', '/categorias/999', [
                'nome' => 'Saúde',
                'tipo' => 'despesa',
            ]),
            404,
            'Categoria não encontrada.'
        );
    }

    public function testNaoAlteraTipoDeCategoriaComLancamentos(): void
    {
        $this->assertErro(
            $this->enviar('PUT', '/categorias/1', [
                'nome' => 'Salário',
                'tipo' => 'despesa',
            ]),
            409,
            'Não é possível alterar o tipo de uma categoria que possui lançamentos.'
        );
    }

    public function testExcluiCategoriaSemLancamentos(): void
    {
        $criada = $this->enviar('POST', '/categorias', [
            'nome' => 'Temporária',
            'tipo' => 'despesa',
        ]);
        $id = $this->decodificar($criada)['id'];
        $response = $this->enviar('DELETE', '/categorias/' . $id);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['mensagem' => 'Categoria excluída com sucesso.'],
            $this->decodificar($response)
        );
        $this->assertErro(
            $this->enviar('GET', '/categorias/' . $id),
            404,
            'Categoria não encontrada.'
        );
    }

    public function testNaoExcluiCategoriaVinculada(): void
    {
        $this->assertErro(
            $this->enviar('DELETE', '/categorias/1'),
            409,
            'Não é possível excluir uma categoria que possui lançamentos.'
        );
    }

    public function testExclusaoDeCategoriaInexistente(): void
    {
        $this->assertErro(
            $this->enviar('DELETE', '/categorias/999'),
            404,
            'Categoria não encontrada.'
        );
    }

    public function testListaLancamentos(): void
    {
        $response = $this->enviar('GET', '/lancamentos');
        $lancamentos = $this->decodificar($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(5, $lancamentos);
        self::assertSame(
            [
                'id' => 1,
                'descricao' => 'Salário',
                'valor' => 3500,
                'data' => '2026-11-05',
                'tipo' => 'receita',
                'categoria_id' => 1,
            ],
            $lancamentos[0]
        );
    }

    public function testFiltraLancamentosPorPeriodoCategoriaETipo(): void
    {
        $response = $this->enviar(
            'GET',
            '/lancamentos?data_inicio=2026-11-08&data_fim=2026-11-10&categoria_id=5&tipo=despesa'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([5], array_column($this->decodificar($response), 'id'));
    }

    public function testFiltroComPeriodoInvertidoRetorna422(): void
    {
        $this->assertErro(
            $this->enviar('GET', '/lancamentos?data_inicio=2026-11-10&data_fim=2026-11-01'),
            422,
            'A data inicial não pode ser posterior à data final.',
            ['data_inicio', 'data_fim']
        );
    }

    public function testFiltroPorCategoriaInexistenteRetorna404(): void
    {
        $this->assertErro(
            $this->enviar('GET', '/lancamentos?categoria_id=999'),
            404,
            'Categoria não encontrada.',
            ['categoria_id']
        );
    }

    public function testConsultaLancamentoExistente(): void
    {
        $response = $this->enviar('GET', '/lancamentos/3');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Supermercado', $this->decodificar($response)['descricao']);
    }

    public function testConsultaLancamentoInexistente(): void
    {
        $this->assertErro(
            $this->enviar('GET', '/lancamentos/999'),
            404,
            'Lançamento não encontrado.'
        );
    }

    public function testCadastraLancamento(): void
    {
        $response = $this->enviar('POST', '/lancamentos', $this->lancamentoValido());

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            [
                'id' => 6,
                'descricao' => 'Cinema',
                'valor' => 45.9,
                'data' => '2026-11-10',
                'tipo' => 'despesa',
                'categoria_id' => 2,
            ],
            $this->decodificar($response)
        );
    }

    public function testLancamentoSemCamposObrigatoriosRetorna400(): void
    {
        $this->assertErro(
            $this->enviar('POST', '/lancamentos', ['descricao' => 'Cinema']),
            400,
            'Campos obrigatórios ausentes',
            ['valor', 'data', 'tipo', 'categoria_id']
        );
    }

    public function testOperacaoDeEscritaExigeJson(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'POST',
            'http://localhost/categorias'
        )->withHeader('Content-Type', 'text/plain');
        $response = $this->app->handle($request);

        $this->assertErro(
            $response,
            400,
            'O Content-Type da requisição deve ser application/json.'
        );
    }

    public function testLancamentoComValorInvalidoRetorna422(): void
    {
        $body = $this->lancamentoValido();
        $body['valor'] = -5;

        $this->assertErro(
            $this->enviar('POST', '/lancamentos', $body),
            422,
            'O valor deve ser um número maior que zero.',
            ['valor']
        );
    }

    public function testLancamentoComCategoriaInexistenteRetorna404(): void
    {
        $body = $this->lancamentoValido();
        $body['categoria_id'] = 999;

        $this->assertErro(
            $this->enviar('POST', '/lancamentos', $body),
            404,
            'Categoria não encontrada.',
            ['categoria_id']
        );
    }

    public function testTipoDoLancamentoDeveCombinarComCategoria(): void
    {
        $body = $this->lancamentoValido();
        $body['tipo'] = 'receita';

        $this->assertErro(
            $this->enviar('POST', '/lancamentos', $body),
            422,
            'O tipo do lançamento deve corresponder ao tipo da categoria.',
            ['tipo', 'categoria_id']
        );
    }

    public function testAtualizaLancamento(): void
    {
        $body = $this->lancamentoValido();
        $body['descricao'] = 'Aluguel atualizado';
        $response = $this->enviar('PUT', '/lancamentos/2', $body);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'id' => 2,
                'descricao' => 'Aluguel atualizado',
                'valor' => 45.9,
                'data' => '2026-11-10',
                'tipo' => 'despesa',
                'categoria_id' => 2,
            ],
            $this->decodificar($response)
        );
    }

    public function testAtualizacaoDeLancamentoComDataInvalida(): void
    {
        $body = $this->lancamentoValido();
        $body['data'] = '2026-02-30';

        $this->assertErro(
            $this->enviar('PUT', '/lancamentos/2', $body),
            422,
            'A data deve ser válida e estar no formato AAAA-MM-DD.',
            ['data']
        );
    }

    public function testAtualizacaoDeLancamentoInexistente(): void
    {
        $this->assertErro(
            $this->enviar('PUT', '/lancamentos/999', $this->lancamentoValido()),
            404,
            'Lançamento não encontrado.'
        );
    }

    public function testExcluiLancamento(): void
    {
        $response = $this->enviar('DELETE', '/lancamentos/1');
        $lista = $this->decodificar($this->enviar('GET', '/lancamentos'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['mensagem' => 'Lançamento excluído com sucesso.'],
            $this->decodificar($response)
        );
        self::assertCount(4, $lista);
        self::assertNotContains(1, array_column($lista, 'id'));
    }

    public function testExclusaoDeLancamentoInexistente(): void
    {
        $this->assertErro(
            $this->enviar('DELETE', '/lancamentos/999'),
            404,
            'Lançamento não encontrado.'
        );
    }

    public function testConsultaSaldoAtual(): void
    {
        $response = $this->enviar('GET', '/resumo/saldo');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['receitas' => 4300, 'despesas' => 1831.15, 'saldo' => 2468.85],
            $this->decodificar($response)
        );
    }

    public function testConsultaResumoDoPeriodo(): void
    {
        $response = $this->enviar(
            'GET',
            '/resumo/periodo?data_inicio=2026-11-06&data_fim=2026-11-08'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'receitas' => 0,
                'despesas' => 1650.75,
                'saldo' => -1650.75,
                'data_inicio' => '2026-11-06',
                'data_fim' => '2026-11-08',
            ],
            $this->decodificar($response)
        );
    }

    public function testResumoDoPeriodoExigeAsDuasDatas(): void
    {
        $this->assertErro(
            $this->enviar('GET', '/resumo/periodo?data_inicio=2026-11-01'),
            400,
            'Informe a data inicial e a data final.',
            ['data_fim']
        );
    }

    public function testResumoDoPeriodoRejeitaDatasInvalidas(): void
    {
        $this->assertErro(
            $this->enviar('GET', '/resumo/periodo?data_inicio=2026-02-30&data_fim=2026-03-01'),
            422,
            'As datas devem ser válidas e estar no formato AAAA-MM-DD.',
            ['data_inicio', 'data_fim']
        );
    }

    public function testRotaInexistenteRetornaJson(): void
    {
        $this->assertErro(
            $this->enviar('GET', '/rota-inexistente'),
            404,
            'Endpoint não encontrado.'
        );
    }

    public function testMigraTransacoesDoFormatoAnterior(): void
    {
        $arquivo = $this->diretorioTemporario . '/transacoes.json';
        file_put_contents($arquivo, json_encode([
            [
                'id' => 1,
                'descricao' => 'Compra antiga',
                'valor' => 25,
                'tipo' => 'despesa',
                'categoria' => 'Moradia',
                'data' => '2026-11-06',
            ],
        ], JSON_THROW_ON_ERROR));

        $appMigrada = require __DIR__ . '/../src/app.php';
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/lancamentos/1'
        );
        $response = $appMigrada->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'id' => 1,
                'descricao' => 'Compra antiga',
                'valor' => 25,
                'tipo' => 'despesa',
                'data' => '2026-11-06',
                'categoria_id' => 2,
            ],
            $this->decodificar($response)
        );
    }

    private function enviar(
        string $metodo,
        string $caminho,
        ?array $body = null
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $metodo,
            'http://localhost' . $caminho
        );

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    private function decodificar(ResponseInterface $response): array
    {
        self::assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type')
        );
        $dados = json_decode((string) $response->getBody(), true);
        self::assertIsArray($dados);

        return $dados;
    }

    private function assertErro(
        ResponseInterface $response,
        int $status,
        string $mensagem,
        ?array $campos = null
    ): void {
        self::assertSame($status, $response->getStatusCode());
        $dados = $this->decodificar($response);
        self::assertSame($mensagem, $dados['erro']);

        if ($campos !== null) {
            self::assertSame($campos, $dados['campos']);
        }
    }

    private function lancamentoValido(): array
    {
        return [
            'descricao' => 'Cinema',
            'valor' => 45.90,
            'data' => '2026-11-10',
            'tipo' => 'despesa',
            'categoria_id' => 2,
        ];
    }
}
