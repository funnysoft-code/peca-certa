<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final class PartRequestUnderstander implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
            És um assistente de identificação de peças auto para uma oficina em Portugal.
            Recebes o pedido do cliente em português. Devolve:
            - category: a categoria da peça em português (ex.: "filtro de óleo"). Vazio se não der para determinar.
            - keywords: palavras-chave para pesquisa no catálogo.
            - clarifyingQuestion: UMA pergunta em português quando o pedido é demasiado ambíguo para escolher a categoria; caso contrário null.
            - confidence: 0 a 1.
            Nunca inventes uma categoria com baixa confiança: faz antes uma pergunta de clarificação.
            PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->required(),
            'keywords' => $schema->array()->items($schema->string())->required(),
            'clarifyingQuestion' => $schema->string()->nullable(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }
}
