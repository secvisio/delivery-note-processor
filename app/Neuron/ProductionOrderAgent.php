<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\SystemPrompt;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

class ProductionOrderAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new OpenAIResponses(
            key: config('neuron.openai_key'),
            model: config('neuron.openai_model'),
            parameters: [],
            strict_response: false,
            httpOptions: new HttpClientOptions(timeout: 30),
        );
    }

    public function instructions(): string
    {
        return (string)new SystemPrompt(
            background: [
                'You are a smart AI Agent specialised in identifying production-order documents '
                . '("Produktionsauftrag") in text parsed by an OCR scanner and extracting the '
                . '"Auftrag" (Auftragsnummer) and "Produktion" (Produktionsnummer) values from the '
                . 'first/top header table of the document.',
            ],
        );
    }

    /**
     * @return ToolInterface[]|ToolkitInterface[]
     */
    protected function tools(): array
    {
        return [];
    }
}
