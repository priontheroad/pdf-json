<?php
// By Priscila Floriano  - 26/06/2023
namespace App\Http\Controllers;

use Smalot\PdfParser\Parser;
use Illuminate\Http\Request;

class PDFController extends Controller
{
// Metodos destinados a buscar os valorers de pis/pasep e cofins no extrato do Simples Nacional. Não lê arquivos escaneados, só original.
// A biblioteca utilizada é a smalot pdf parser instalando com o comendo composer require smalot/pdfparser
// Eu usei a versão  "smalot/pdfparser": "^0.3.2 || ^1.0"

    public function encontrarValoresPisCofinsEmDocumento($textoCompleto, $sentencasEncontradas, $cnpjEstabelecimentos)
    {
        $dados = [];

        foreach ($sentencasEncontradas as $sentenca) {
            // Filtrar os CNPJs estabelecimentos que contêm a sentença
            $cnpjsFiltrados = array_filter($cnpjEstabelecimentos, function ($cnpjEstabelecimento) use ($textoCompleto, $sentenca) {
                return strpos($textoCompleto, $cnpjEstabelecimento) !== false;
            });

            foreach ($cnpjsFiltrados as $cnpjEstabelecimento) {
                $valoresPisCofins = $this->encontrarValoresPisCofins($textoCompleto, $sentenca, $cnpjEstabelecimento);
                foreach ($valoresPisCofins as $valores) {
                    $dados[] = $valores;
                }
            }
        }
        return $dados;
    }

    public function encontrarSentencas($textoCompleto, $cnpjEstabelecimento)
    {
        $sentencasEncontradas = [];

        $sentencas = [
            'Prestação de Serviços, exceto para o exterior - Não sujeitos ao fator “r” e tributados pelo Anexo III,sem retenção/substituição tributária de ISS, com ISS devido ao próprio Município do estabelecimento',
        ];

        foreach ($sentencas as $sentenca) {
            if (strpos($textoCompleto, $sentenca) !== false && strpos($textoCompleto, $cnpjEstabelecimento) !== false) {
                $sentencasEncontradas[] = $sentenca;
            }
        }
        return $sentencasEncontradas;
    }
    private function buscarCnpjs($pdf)
    {
        $textoCompleto = $pdf->getText();
        preg_match_all('/CNPJ Estabelecimento:\s*([0-9]{2}\.[0-9]{3}\.[0-9]{3}\/[0-9]{4}-[0-9]{2})/', $textoCompleto, $cnpjsEncontrados);
        return $cnpjsEncontrados[1] ?? [];
    }

    public function encontrarValoresPisCofins($textoCompleto, $sentenca, $cnpjEstabelecimentos)
    {
        $valoresEncontrados = [];
        if (is_string($cnpjEstabelecimentos)) {
            $cnpjEstabelecimentos = [$cnpjEstabelecimentos];
        }

        $posicoesSentenca = [];
        $posicaoSentenca = strpos($textoCompleto, $sentenca);

        while ($posicaoSentenca !== false) {
            $posicoesSentenca[] = $posicaoSentenca;
            $posicaoSentenca = strpos($textoCompleto, $sentenca, $posicaoSentenca + 1);
        }

        foreach ($posicoesSentenca as $posicaoSentenca) {
            // pegar texto entre a sentença e o fim do texto
            $textoAposSentenca = substr($textoCompleto, $posicaoSentenca + strlen($sentenca));

            // Separando as linhas do texto após a sentença...
            $linhas = explode("\n", $textoAposSentenca);

            // Buscando a linha que contém o cabeçalho das colunas
            $indiceCabecalho = array_search("IRPJ\tCSLL\tCOFINS\tPIS/Pasep\tINSS/CPP\tICMS\tIPI\tISS\tTotal\t", $linhas);

            if ($indiceCabecalho !== false && isset($linhas[$indiceCabecalho + 1])) {
                // Pegar a linha seguinte que tem os valores de pis cofins daí
                $linhaValores = $linhas[$indiceCabecalho + 1];

                // Separando os valores por tabulação
                $valores = explode("\t", $linhaValores);

                // Removendo espaços em branco e caracteres especiais dos valores
                $valores = array_map('trim', $valores);

                // Checando se a linha tem valores de PIS, PASEP e COFINS
                if (count($valores)) {
                    $cnpjAtual = null; //

                    if (count($cnpjEstabelecimentos)) {
                        // procurando cnpj do estabelecimento na linha
                        foreach ($cnpjEstabelecimentos as $cnpjEstabelecimento) {
                            if (strpos($linhaValores, $cnpjEstabelecimento) !== false) {
                                $cnpjAtual = $cnpjEstabelecimento; //
                                break;
                            }
                        }

                        if ($cnpjAtual === null) {
                            $cnpjAtual = array_shift($cnpjEstabelecimentos); // Obtenha o próximo CNPJ estabelecimento da lista
                        }
                    } else {
                        $cnpjAtual = end($valoresEncontrados)['cnpj_estabelecimento'];
                    }

                    $valoresEncontrados[] = [
                        'cnpj_estabelecimento' => $cnpjAtual,
                        'pis_pasep' => isset($valores[3]) ? $valores[3] : null,
                        'cofins' => isset($valores[2]) ? $valores[2] : null,
                    ];
                }
            }
        }
        return $valoresEncontrados;
    }

    public function pdfParaJson(Request $request)
    {
        if ($request->hasFile('pdf')) {
            $caminhoPdf = $request->file('pdf')->getPathname();

            $parser = new Parser();
            $pdf = $parser->parseFile($caminhoPdf);

            $cnpjsEncontrados = $this->buscarCnpjs($pdf);

            $dados = [];

            foreach ($cnpjsEncontrados as $cnpjEstabelecimento) {
                $textoCompleto = $pdf->getText();

                $sentencasEncontradas = $this->encontrarSentencas($textoCompleto, $cnpjEstabelecimento);

                $valoresPisCofins = $this->encontrarValoresPisCofinsEmDocumento($textoCompleto, $sentencasEncontradas, $cnpjsEncontrados);
                $valoresPisCofins = $this->encontrarValoresPisCofinsEmDocumento($textoCompleto, $sentencasEncontradas, $cnpjsEncontrados);

                $primeiroItem = reset($valoresPisCofins); // Obter o primeiro item do array
                $ultimoItem = end($valoresPisCofins); // Obter o último item do array

                $dados[] = $primeiroItem; // Adicionar o primeiro item ao array $dados
                $dados[] = $ultimoItem; //

                foreach ($valoresPisCofins as $valores) {
                    $pisPasep = $valores['pis_pasep'];
                    $cofins = $valores['cofins'];

                    $objetoCnpj = [
                        'cnpj_estabelecimento' => $valores['cnpj_estabelecimento'],
                        'pis_pasep' => $pisPasep,
                        'cofins' => $cofins,
                    ];
                    $dados[] = $objetoCnpj;
                }
            }

            if (empty($dados)) {
                return response()->json(['error' => 'Nenhum dado correspondente encontrado no arquivo PDF ou a correspondencia esta ilegivel.'], 400);
            }
            $countCnpjs = count($cnpjsEncontrados);
            $response = [
                'dados' => array_slice($dados, 0, $countCnpjs)
            ];
            return response()->json($response);

        }
        return response()->json(['error' => 'Nenhum arquivo PDF encontrado.'], 400);
    }
}

