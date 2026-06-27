<?php

declare(strict_types=1);

// ──────────────────────────────────────────────
// DTOs — Data Transfer Objects
//
// readonly garante imutabilidade total: após construído, nenhum campo pode ser
// alterado. Isso evita que uma Action modifique dados que outra Action já validou.
// Constructor promotion elimina a necessidade de declarar + atribuir cada campo.
// ──────────────────────────────────────────────

final readonly class TripInput
{
    public function __construct(
        public float $timeHours,
        public float $speedKmh,
        public float $fuelPricePerLiter,
    ) {}
}

final readonly class TripResult
{
    public function __construct(
        public float $timeHours,
        public float $speedKmh,
        public float $distanceKm,
        public float $litersUsed,
        public float $totalCost,
        public float $fuelPricePerLiter,
        public string $recordedAt,
    ) {}

    // Serialização separada do construtor: o DTO não sabe que vai ser salvo em
    // JSON. Ele só sabe se transformar em array. Quem decide persistir é o Repository.
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'time_hours' => $this->timeHours,
            'speed_kmh' => $this->speedKmh,
            'distance_km' => $this->distanceKm,
            'liters_used' => $this->litersUsed,
            'total_cost' => $this->totalCost,
            'fuel_price_per_liter' => $this->fuelPricePerLiter,
            'recorded_at' => $this->recordedAt,
        ];
    }
}

// ──────────────────────────────────────────────
// Domain Exception
//
// Extende RuntimeException (unchecked) em vez de Exception (checked).
// O domínio lança — a camada de entrada (CLI/Controller) decide como tratar.
// Separar exceções de negócio de exceções de infraestrutura permite tratar
// cada tipo de forma diferente no handler global.
// ──────────────────────────────────────────────

final class InvalidTripInputException extends RuntimeException {}

// ──────────────────────────────────────────────
// Action: ValidateTripInputAction
//
// Responsabilidade única: receber scalars brutos do CLI, validar as regras de
// negócio e devolver um TripInput já seguro. Se algo estiver errado, lança a
// Domain Exception — nunca retorna null ou bool de erro.
//
// Em Laravel isso equivaleria à lógica de um FormRequest combinada com
// validações de negócio que vão além do "campo obrigatório".
// ──────────────────────────────────────────────

final class ValidateTripInputAction
{
    public function execute(float $time, float $speed, float $price): TripInput
    {
        // Regras de domínio: valores fisicamente impossíveis são rejeitados
        // antes de chegar no cálculo. Fail fast: quanto antes quebrar, mais
        // fácil de debugar e mais seguro para dados persistidos.
        if ($time <= 0) {
            throw new InvalidTripInputException('Tempo deve ser maior que zero.');
        }

        if ($speed <= 0) {
            throw new InvalidTripInputException('Velocidade deve ser maior que zero.');
        }

        // 400 km/h como teto razoável para detectar digitação errada
        // (ex: 850 no lugar de 85).
        if ($speed > 400) {
            throw new InvalidTripInputException("Velocidade improvável: {$speed} km/h excede 400 km/h.");
        }

        if ($price < 0) {
            throw new InvalidTripInputException('Preço do combustível não pode ser negativo.');
        }

        // Só chegamos aqui se tudo for válido. O TripInput é imutável,
        // então esse "contrato de validade" se propaga por todo o fluxo.
        return new TripInput($time, $speed, $price);
    }
}

// ──────────────────────────────────────────────
// Action: CalculateTripAction
//
// Responsabilidade única: receber um TripInput já validado e produzir um
// TripResult com todos os valores calculados.
//
// A constante EFFICIENCY_KM_PER_LITER está aqui — não no DTO nem no Reporter —
// porque ela é uma regra de negócio do cálculo, não de apresentação.
// ──────────────────────────────────────────────

final class CalculateTripAction
{
    // Constante de classe em vez de magic number espalhado pelo código.
    // Se o carro mudar de eficiência, só alteramos aqui.
    private const EFFICIENCY_KM_PER_LITER = 12.0;

    public function execute(TripInput $input): TripResult
    {
        $distance = $input->timeHours * $input->speedKmh;
        $liters = $distance / self::EFFICIENCY_KM_PER_LITER;
        $cost = $liters * $input->fuelPricePerLiter;

        // date() capturado aqui, no momento do cálculo, não na persistência.
        // Isso garante que o timestamp reflita quando o dado foi processado,
        // não quando foi gravado no disco (que pode ser milissegundos depois).
        return new TripResult(
            timeHours: $input->timeHours,
            speedKmh: $input->speedKmh,
            distanceKm: $distance,
            litersUsed: $liters,
            totalCost: $cost,
            fuelPricePerLiter: $input->fuelPricePerLiter,
            recordedAt: date('Y-m-d H:i:s'),
        );
    }
}

// ──────────────────────────────────────────────
// Repository: TripHistoryRepository
//
// Abstrai onde e como os dados são persistidos. O resto do código não sabe
// se é JSON, SQLite ou uma API — só chama save() e loadAll().
// Trocar o storage no futuro não exige alterar Actions nem o CLI.
// ──────────────────────────────────────────────

final class TripHistoryRepository
{
    private string $path;

    public function __construct(string $storagePath = __DIR__.'/trips.json')
    {
        $this->path = $storagePath;
    }

    public function save(TripResult $result): void
    {
        // Carrega o histórico existente antes de anexar.
        // Isso garante que cada execução do programa acumule viagens
        // em vez de sobrescrever o arquivo com apenas a sessão atual.
        $history = $this->loadAll();
        $history[] = $result->toArray();

        file_put_contents(
            $this->path,
            // JSON_PRETTY_PRINT: arquivo legível por humanos.
            // JSON_UNESCAPED_UNICODE: preserva acentos sem escapar para \uXXXX.
            json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    /** @return list<array<string, mixed>> */
    public function loadAll(): array
    {
        if (! file_exists($this->path)) {
            return [];
        }

        // O operador ?: garante que uma leitura vazia não quebre o json_decode.
        // is_array() + array_values() garante que o retorno seja sempre uma lista
        // indexada, mesmo se o JSON estiver malformado com chaves associativas.
        $decoded = json_decode(file_get_contents($this->path) ?: '[]', true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}

// ──────────────────────────────────────────────
// Reporter: TripReporter
//
// Responsabilidade exclusiva de apresentação. Não calcula, não persiste.
// Equivale a um API Resource no Laravel: recebe dados prontos e formata a saída.
// Separar formatação da lógica permite trocar a UI (terminal → HTML → JSON)
// sem tocar em nenhuma outra camada.
// ──────────────────────────────────────────────

final class TripReporter
{
    public function printTripReport(TripResult $result, int $tripNumber): void
    {
        // sprintf com %-16s: alinha o valor à esquerda em 16 caracteres,
        // garantindo que todas as linhas do relatório fiquem alinhadas
        // independente do tamanho do valor (ex: "1.234" vs "70.833").
        echo PHP_EOL;
        echo '┌──────────────────────────────────────┐'.PHP_EOL;
        echo sprintf("│  VIAGEM #%-28s│\n", $tripNumber.' — '.$result->recordedAt);
        echo '├──────────────────────────────────────┤'.PHP_EOL;
        echo sprintf("│  Tempo             : %-16s│\n", number_format($result->timeHours, 2).' h');
        echo sprintf("│  Velocidade média  : %-16s│\n", number_format($result->speedKmh, 2).' km/h');
        echo sprintf("│  Distância         : %-16s│\n", number_format($result->distanceKm, 3).' km');
        echo sprintf("│  Combustível gasto : %-16s│\n", number_format($result->litersUsed, 3).' L');
        echo sprintf("│  Preço por litro   : R$ %-13s│\n", number_format($result->fuelPricePerLiter, 2));
        echo sprintf("│  Custo total       : R$ %-13s│\n", number_format($result->totalCost, 2));
        echo '└──────────────────────────────────────┘'.PHP_EOL;
    }

    /** @param list<TripResult> $results */
    public function printFinalSummary(array $results): void
    {
        if (count($results) === 0) {
            return;
        }

        $totalLiters = array_sum(array_map(fn (TripResult $r) => $r->litersUsed, $results));
        $totalDistance = array_sum(array_map(fn (TripResult $r) => $r->distanceKm, $results));
        $totalCost = array_sum(array_map(fn (TripResult $r) => $r->totalCost, $results));

        // array_reduce com carry null: pattern seguro para encontrar o máximo
        // em uma coleção de objetos. Evita array_map + max() que exigiria dois
        // passos e perderia a referência ao objeto original.
        // "Mais econômica" = maior km/L individual, não menor custo absoluto
        // (uma viagem mais longa pode custar mais mas ser mais eficiente).
        $mostEfficient = array_reduce(
            $results,
            fn (?TripResult $carry, TripResult $r) => $carry === null || ($r->distanceKm / $r->litersUsed) > ($carry->distanceKm / $carry->litersUsed)
                    ? $r
                    : $carry,
        );

        // array_search com strict=true (terceiro parâmetro): compara por
        // identidade de objeto (===), não por valor. Sem isso, dois TripResults
        // com os mesmos dados retornariam o índice errado.
        $mostEfficientIndex = (int) array_search($mostEfficient, $results, true) + 1;

        echo PHP_EOL;
        echo '╔══════════════════════════════════════╗'.PHP_EOL;
        echo '║         RELATÓRIO FINAL              ║'.PHP_EOL;
        echo '╠══════════════════════════════════════╣'.PHP_EOL;
        echo sprintf("║  Viagens registradas : %-13s║\n", count($results));
        echo sprintf("║  Total de litros     : %-13s║\n", number_format($totalLiters, 3).' L');
        echo sprintf("║  Distância total     : %-13s║\n", number_format($totalDistance, 3).' km');
        echo sprintf("║  Gasto total         : R$ %-11s║\n", number_format($totalCost, 2));
        echo sprintf("║  Média consumo       : %-13s║\n", number_format($totalDistance / $totalLiters, 2).' km/L');
        echo sprintf("║  Mais econômica      : Viagem #%-8s║\n", $mostEfficientIndex);
        echo '╚══════════════════════════════════════╝'.PHP_EOL;
    }
}

// ──────────────────────────────────────────────
// Helper: readPositiveFloat
//
// Lê e valida no momento da digitação, campo a campo.
// O problema de aceitar qualquer string e só barrar no Action é que o usuário
// já preencheu todos os campos antes de ver o erro — UX ruim.
//
// is_numeric() rejeita strings como "abc" mas aceita "6.49", "6,49" (após
// normalização) e notação científica como "1e3". Mais robusto que tentar
// detectar letras manualmente.
//
// O loop garante que o programa não avance para o próximo campo enquanto
// o atual estiver inválido — sem lançar exceção, sem reiniciar a viagem.
// ──────────────────────────────────────────────

function readPositiveFloat(string $prompt, bool $allowZero = false): float
{
    while (true) {
        $raw = trim((string) readline($prompt));
        $normalized = str_replace(',', '.', $raw);

        // is_numeric valida que a entrada é um número antes do cast.
        // Sem isso, (float)'abc' retorna 0.0 silenciosamente e passa
        // como zero na validação do Action — bug difícil de notar.
        if (! is_numeric($normalized)) {
            echo "  ⚠️  Valor inválido: \"{$raw}\" não é um número. Tente novamente.".PHP_EOL;

            continue;
        }

        $value = (float) $normalized;

        // allowZero=true é usado para o preço do combustível:
        // tecnicamente possível ser 0 (ex: abastecimento grátis em teste).
        // Para tempo e velocidade, zero não faz sentido fisicamente.
        if ($value < 0 || (! $allowZero && $value === 0.0)) {
            $limit = $allowZero ? 'negativo' : 'zero ou negativo';
            echo "  ⚠️  Valor inválido: não pode ser {$limit}. Tente novamente.".PHP_EOL;

            continue;
        }

        return $value;
    }
}

// ──────────────────────────────────────────────
// Entrypoint — CLI
//
// Instancia as dependências manualmente (poor man's DI container).
// Em Laravel isso seria resolvido pelo Service Container via construtor.
// O loop só coordena: lê entrada, delega para as classes certas, trata erros.
// Nenhuma lógica de negócio vive aqui — equivale a um Controller magro.
// ──────────────────────────────────────────────

$validator = new ValidateTripInputAction;
$calculator = new CalculateTripAction;
$reporter = new TripReporter;
$repository = new TripHistoryRepository;

/** @var list<TripResult> $sessionResults */
$sessionResults = [];

echo '⛽ Calculadora de Consumo — Modo Avançado'.PHP_EOL;
echo "Digite 's' no tempo para encerrar.".PHP_EOL;

while (true) {
    $tripNumber = count($sessionResults) + 1;

    echo PHP_EOL."── Viagem #{$tripNumber} ──────────────────────────".PHP_EOL;

    $rawTime = readline('Tempo de viagem (h): ');

    // Sentinela de saída verificada antes de converter para float:
    // (float)'s' retornaria 0.0 e dispararia erro de validação
    // em vez de encerrar o programa como o usuário esperava.
    if (strtolower(trim((string) $rawTime)) === 's') {
        break;
    }

    // O tempo já foi lido como string para checar o sentinela 's'.
    // Reaproveitamos o valor em vez de pedir de novo — mas agora
    // passamos pela mesma validação que speed e price.
    $normalized = str_replace(',', '.', trim((string) $rawTime));
    if (! is_numeric($normalized) || (float) $normalized <= 0) {
        echo '  ⚠️  Valor inválido para tempo. Tente novamente.'.PHP_EOL;

        continue;
    }
    $time = (float) $normalized;
    $speed = readPositiveFloat('Velocidade média (km/h): ');
    $price = readPositiveFloat('Preço do combustível (R$/L): ', allowZero: true);

    try {
        // Fluxo feliz: valida → calcula → persiste → exibe.
        // Cada passo recebe o output do anterior, nenhum sabe dos outros.
        $input = $validator->execute($time, $speed, $price);
        $result = $calculator->execute($input);

        $repository->save($result);
        $reporter->printTripReport($result, $tripNumber);

        $sessionResults[] = $result;
    } catch (InvalidTripInputException $e) {
        // Só capturamos a Domain Exception aqui.
        // Exceções de infraestrutura (ex: falha ao gravar JSON) propagam
        // normalmente e encerram o programa com stack trace — comportamento
        // correto para erros inesperados em ambiente de desenvolvimento.
        echo "  ⚠️  Entrada inválida: {$e->getMessage()}".PHP_EOL;
    }
}

if (count($sessionResults) === 0) {
    echo PHP_EOL.'Nenhuma viagem registrada. Até mais!'.PHP_EOL;
    exit(0);
}

$reporter->printFinalSummary($sessionResults);
echo PHP_EOL.'💾 Histórico salvo em trips.json'.PHP_EOL;
