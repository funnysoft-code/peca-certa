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
            1. Usa as ferramentas para encontrar o(s) OE correcto(s). Preferência: search_parts_by_vin com query em inglês; se ambíguo, decode_vin + list_main_groups + list_sub_groups + list_bom_parts (get_part_info se a linha for dúbia).
            2. Nunca inventes números OE. Só selecciona OEs que as ferramentas devolveram.
            3. Se tiveres confiança alta em 1 ou N OEs sem ambiguidade, status=selected e preenche oeParts (oeNumber, description, brand="OE").
            4. Se estiveres inseguro sobre a peça (não sobre a marca), status=needs_input: pergunta em PT-PT sobre modelo/lado/variante da peça, options com 2–6 escolhas concretas (quando possível), e oeParts vazio. NÃO continues a chamar ferramentas depois de decidires perguntar — a resposta estruturada é o fim do turno.
            5. Se qualquer ferramenta devolver error=unsupported_brand: NÃO perguntes modelo nem ano. status=needs_input com question a explicar que o catálogo/WMI não está configurado e options = availableBrands da ferramenta (chaves de catálogo).
            6. Pedidos multi-peça: um único status=selected com vários oeParts.
            7. confidence entre 0 e 1.
            8. question e options só quando needs_input; caso contrário question=null e options=[].
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
