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

class FrachtbriefAgent extends Agent
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
                'You are a smart AI Agent specialised in identifying freight-waybill documents '
                . '("Frachtbrief") in text parsed by an OCR scanner. The primary identifying signal '
                . 'is an "Order Nummer" field whose value looks like YYMMDD-NN (for example '
                . '"260630-01"). You also extract the Order Nummer, the Abholdatum (pickup date) '
                . 'and the recipient company (Firma / Empfängername) from the document.',
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
