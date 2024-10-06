<?php

/**
 * @file plugins/generic/externalVocab/ExternalVocabPlugin.php
 *
 * Copyright (c) 2024 Mä ja sä
 * Distributed under the GNU GPL v3. For full terms see LICENSE or https://www.gnu.org/licenses/gpl-3.0.txt
 *
 * @class ExternalVocab
 * @ingroup plugins_generic_externalVocab
 *
 */

namespace APP\plugins\generic\externalVocab;

use GuzzleHttp\Client;

use APP\core\Application;
use PKP\plugins\GenericPlugin;
use PKP\core\JSONMessage;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\Hook;
use PKP\submission\SubmissionKeywordDAO;

class ExternalVocabPlugin extends GenericPlugin
{

    /**
     * Constants
     */

    // Define which languages are supported in the vocabulary
    const ALLOWED_LANGS = ['fi', 'sv', 'en'];


    /**
     * Public 
     */

    // Generic Plugin methods

    public function register($category, $path, $mainContextId = null)
    {
        if (Application::isUnderMaintenance()) {
            return true;
        }
        $success = parent::register($category, $path);
        if ($success && $this->getEnabled()) {
            // Add hook
            Hook::add('API::vocabs::external', $this->setData(...));
        }
        return $success;
    }

    public function getActions($request, $actionArgs)
    {
        return parent::getActions($request, $actionArgs);
    }

    public function getDisplayName()
    {
        return __('plugins.generic.externalVocab.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.externalVocab.description');
    }

    public function getCanEnable()
    {
        return !!Application::get()->getRequest()->getContext();
    }

    public function getCanDisable()
    {
        return !!Application::get()->getRequest()->getContext();
    }

    public function manage($args, $request)
    {
        return parent::manage($args, $request);
    }

    // Own methods

    /**
     * Public
     */

    public function setData(string $hookName, array $args): bool
    {
        $vocab = $args[0];
        $term = $args[1];
        $locale = $args[2];
        $data = &$args[3];
        $entries = &$args[4];
        $illuminateRequest = $args[5];
        $response = $args[6];
        $request = $args[7];

        // Here we define which form field the plugin is triggered in. In this case Keywords field.
        // You can also define the language is the specific field while some vocabularies might only work
        // with specific languages.
        // Note that the current development version of the core only supports extending Keywords.
        // However, this will be extended to other fields as well, like Discipline, as well.
        if ($vocab !== SubmissionKeywordDAO::CONTROLLED_VOCAB_SUBMISSION_KEYWORD || !in_array($locale, self::ALLOWED_LANGS)) {
            return false;
        }

        // We call the fetchData function will handle the interaction with the vocabulary
        $resultData = $this->fetchData($term, $locale);

        // We replace the vocabulary data coming from the OJS database with fetched data
        // from the external vocabulary and only show those results as suggestions.
        // If you want to show also suggestions from existing keywords in your own database
        // this is where we can make that decision.
        if (!$resultData) {
            $data = [];
            return false;
        }

        for ($i = 0, $len = count($resultData); $i < $len; $i++) {
            $data[$i] = $resultData[$i];
        }

        return false;
    }

    /**
     * Private
     */

    private function fetchData(?string $term, string $locale): array {

        // You might want to consider sanitazing the search term before it is 
        // passed to an API or used to search within a local file
        $termSanitized = $term ?? "";

        // Here we can set the minimum length for the word that is used for the query
        if (strlen($termSanitized) < 3) {
            return [];
        }

        // The following example connects to an external vocabulary called Finto
        // using an open REST API and Guzzle HTTP Client.
        // This is the part of the code that can vary depending on the vocabulary used.
        // You could rewrite the code to support another REST API based vocabulary or to 
        // interact with a local file for example in the plugin folder. This might work
        // for smaller vocabularies and could be also used easily to define a strict set
        // of keywords locally.

        $client = new Client();
        $response = $client->request(
            'GET',
            "https://api.finto.fi/rest/v1/search?vocab=koko&query=$termSanitized*&lang=$locale",
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-type' => 'application/json'
                ],
            ]
        );

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $data = json_decode($response->getBody()->getContents(), true)['results'] ?? [];

        return collect($data)
            ->unique('uri')
            ->filter()
            ->map(fn (array $d): array =>
            [
                    'term' => $d['prefLabel'],
                    'label' => "{$d['prefLabel']} [ {$d['uri']} ]",
                    'uri' => $d['uri'], // this is the unique identifier that will be stored separately
                    /* Extra items here */
            ])
            ->values()
            ->toArray();

    }
}
