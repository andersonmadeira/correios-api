<?php

namespace App\Correios;

use Goutte\Client;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\DomCrawler\Crawler;

class CorreiosScraper
{

    const CORREIOS_CEP_URL = 'http://www.buscacep.correios.com.br/sistemas/buscacep/buscaCepEndereco.cfm';
    const CORREIOS_PACKAGE_URL = 'https://www2.correios.com.br/sistemas/rastreamento/';
    const CEP_FORM_SELECTOR = '#Geral';

    private $client;

    public function __construct()
    {
        $this->client = new Client();
        $guzzleClient = new GuzzleClient([
            'timeout' => 60
        ]);
        $this->client->setClient($guzzleClient);
    }

    /**
     * Pega informações do CEP no site dos correios e devolve um array de endereços com as informações
     * ou um array vazio se não houver informações ou CEP não for encontrado. Em vez do CEP, também pode ser
     * informado o logradouro, bairro ou outro tipo de pesquisa por nome, os endereços correspondentes (com CEP)
     * serão devolvidos.
     *
     * @param $query string que pode ser um CEP, endereço, bairro etc.
     * @return array de entradas correspontes ao CEP informado
     */
    public function getCepInfo($query)
    {
        $headers = [];
        $values = [];

        $crawler = $this->client->request('GET', self::CEP_URL);

        $form = $crawler->selectButton('Buscar')->form();

        $form['relaxation'] = $query;

        $resultPage = $this->client->submit($form);

        $allLines = $resultPage->filter('.tmptabela tr');
        $lineCount = $allLines->count();

        for ($lineIndex = 0; $lineIndex < $lineCount; $lineIndex++) {
            $line = $allLines->eq($lineIndex);

            $allColumns = $line->children();
            $columnCount = $allColumns->count();

            $address = [];

            for ($columnIndex = 0; $columnIndex < $columnCount; $columnIndex++) {
                $column = $allColumns->eq($columnIndex);
                $columnTagName = $column->getNode(0)->tagName;
                $columnText = $column->text();

                if ($columnTagName === 'th') {
                    $headers[] = $columnText;
                } else if ($columnTagName === 'td') {
                    $address[ $headers[$columnIndex] ] = trim($columnText, self::getUTFSpace());
                }
            }

            if (!empty($address)) {
                $values[] = $address;
            }
        }

        return empty($values) ? [] : $values;
    }

    /**
     * Busca informações do pacote a partir do código de rastreamento informado
     *
     * @param $trackingCode string código de rastreio
     * @return array
     */
    public function getPackageInfo($trackingCode)
    {
        $events = [];
        $crawler = $this->client->request('GET', self::CORREIOS_PACKAGE_URL);

        $form = $crawler->selectButton('Buscar')->form();

        $form['objetos'] = $trackingCode;

        $resultPage = $this->client->submit($form);

        $allLines = $resultPage->filter('.listEvent tr');
        $lineCount = $allLines->count();

        for ($lineIndex = 0; $lineIndex < $lineCount; $lineIndex++) {
            $line = $allLines->eq($lineIndex);

            $event = [];

            $dateEvent = $line->filter('.sroDtEvent');
            $dateEventText = $dateEvent->text();

            preg_match('/[0-9]{2}\/[0-9]{2}\/[0-9]{4}/', $dateEventText, $matches);

            $event['date'] = $matches[0];

            preg_match('/[0-9]{2}\:[0-9]{2}/', $dateEventText, $matches);

            $event['time'] = $matches[0];

            $innerLabel = $dateEvent->filter('label');

            if ( $innerLabel->count() ) {
                $event['location'] = $innerLabel->text();
            } else {
                $event['location'] = explode('<br>', $dateEvent->html())[2];
            }

            $event['location'] = self::sanitizeIrregularSpaces($event['location']);

            $event['title'] = $line->filter('.sroLbEvent strong')->text();

            $rawDescription = $line->filter('.sroLbEvent')->text();

            $event['description'] = self::sanitizeIrregularSpaces($rawDescription);

            $events[] = $event;
        }

        return $events;
    }

    /**
     * Devolve a representação de um espaço em UTF
     *
     * @return string
     */
    private static function getUTFSpace()
    {
        return chr(0xC2).chr(0xA0);
    }

    /**
     * "Sanea" uma string de acordo com espaços irregulares: uso excessivo de quebras de linha, espaços utf
     * e trim a string
     *
     * @param $str
     * @return string string saneada
     */
    private static function sanitizeIrregularSpaces($str)
    {
        $newLinesToRemove = ["\r\n", "\t", "\r", "\n", self::getUTFSpace()];

        $finalStr = trim(str_replace($newLinesToRemove, ' ', $str));

        $finalStr = preg_replace('!\s+!', ' ', $finalStr);

        $finalStr = trim(trim($finalStr, '/'));

        return $finalStr;
    }

}
