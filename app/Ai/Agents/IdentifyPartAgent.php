<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Attributes\Reasoning;
use App\Ai\Concerns\UsesXaiProviderOptions;
use App\Ai\Tools\PartsLink24\DecodeVin;
use App\Ai\Tools\PartsLink24\GetPartInfo;
use App\Ai\Tools\PartsLink24\ListBomParts;
use App\Ai\Tools\PartsLink24\ListMainGroups;
use App\Ai\Tools\PartsLink24\ListSubGroups;
use App\Ai\Tools\PartsLink24\ResolveBrand;
use App\Ai\Tools\PartsLink24\SearchPartsByVin;
use App\Enums\ReasoningEffort;
use App\Enums\XaiModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

#[Provider(Lab::xAI)]
#[Model(XaiModel::Grok43->value)]
#[Reasoning(ReasoningEffort::Low)]
#[MaxSteps(8)]
#[Timeout(90)]
final readonly class IdentifyPartAgent implements Agent, Conversational, HasProviderOptions, HasStructuredOutput, HasTools
{
    use Promptable;
    use UsesXaiProviderOptions;

    /**
     * @param  list<Message>  $history
     */
    public function __construct(
        private array $history = [],
        private ?int $maxSteps = null,
        private ?int $timeoutSeconds = null,
        private ?string $promptCacheKey = null,
    ) {}

    public function maxSteps(): ?int
    {
        return $this->maxSteps;
    }

    public function timeout(): ?int
    {
        return $this->timeoutSeconds;
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
            És um agente de identificação de peças OE (número original) para uma oficina em Portugal.
            Tens ferramentas PartsLink24: resolve_brand, decode_vin, search_parts_by_vin, list_main_groups, list_sub_groups, list_bom_parts, get_part_info.
            O VIN e o pedido do operador vêm na mensagem.

            Regras:
            1. Usa as ferramentas para encontrar o(s) OE correcto(s). Preferência: search_parts_by_vin (query em inglês) para descobrir maingroup/btnr; confirma SEMPRE com list_bom_parts antes de status=selected quando houver variantes ou vários OEs parecidos.
            2. Nunca inventes números OE. Só selecciona OEs que as ferramentas devolveram.
            3. Factory-fit (obrigatório): list_bom_parts devolve factoryFit (true = não cinzento / equipamento de fábrica) e unavailable (true = cinzento / pacote opção). Preferência DURA por factoryFit=true. NUNCA auto-selecciones factoryFit=false se existir factoryFit=true na mesma posição. Só escolhe opção cinzenta se o pedido do operador nomear claramente o pacote (ex. JCW GP, Chrome Line, Bayswater); senão needs_input com opções concretas.
            4. search_parts_by_vin é ruidoso (pode listar pacotes especiais). Não confies no primeiro hit sem BOM.
            5. Auto-select (status=selected) SÓ se confidence >= 0.9 E existir um único OE factory-fit inequívoco (ou N OEs multi-peça todos factory-fit sem ambiguidade). Caso contrário needs_input.
            6. needs_input quando houver qualquer ambiguidade significativa (lado, frente/trás, acabamento/cor, pack, vários OEs factory relevantes, confidence < 0.9). Pergunta em PT-PT; options com 2–6 escolhas concretas do catálogo; oeParts vazio. NÃO continues ferramentas depois de decidires perguntar.
            7. NÃO perguntes modelo/ano/cor do veículo se decode_vin já devolveu esses campos — só ambiguidade da PEÇA.
            8. error=unsupported_brand: needs_input com availableBrands (sem pedir modelo/ano).
            9. error=http_error: tenta outra ferramenta ou needs_input com a limitação (sem inventar OEs).
            9b. error=pl24_auth_error: needs_input a pedir retry/suporte; sem inventar OEs.
            10. Multi-peça: um status=selected com vários oeParts.
            11. confidence entre 0 e 1. question/options só em needs_input (senão question=null, options=[]).
            PROMPT;
    }

    /**
     * @return list<Message>
     */
    public function messages(): iterable
    {
        return $this->history;
    }

    /**
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        return [
            resolve(ResolveBrand::class),
            resolve(DecodeVin::class),
            resolve(SearchPartsByVin::class),
            resolve(ListMainGroups::class),
            resolve(ListSubGroups::class),
            resolve(ListBomParts::class),
            resolve(GetPartInfo::class),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->required(),
            'oeParts' => $schema->array()->items($schema->object([
                'oeNumber' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'brand' => $schema->string()->required(),
            ]))->required(),
            'question' => $schema->string()->nullable(),
            'options' => $schema->array()->items($schema->string())->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }

    protected function xaiPromptCacheKey(): ?string
    {
        return $this->promptCacheKey;
    }
}
