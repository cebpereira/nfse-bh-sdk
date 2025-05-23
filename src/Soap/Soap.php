<?php

namespace NFse\Soap;

use Exception;
use NFse\Config\WebService;
use NFse\Models\Settings;
use SoapClient;
use SoapFault;

class Soap extends SoapClient
{
    private $xml;
    private $method;
    private $webservice;
    private $settings;

    /**
     * conecta ao webservice e verifica disponibilidade do serviço
     *
     * @param NFse\Models\Settings;
     * @param string;
     * @param array;
     */
    public function __construct(Settings $settings, string $method, ?array $options = null)
    {
        try {
            $this->webservice = new WebService($settings);
            $this->method = $method;
            $this->settings = $settings;

            //seta as opções de certificado digital e stream context
            if (is_null($options)) {
                $options = [
                    'encoding' => 'UTF-8',
                    'soap_version' => $this->webservice->soapVersion,
                    'style' => $this->webservice->style,
                    'use' => $this->webservice->use,
                    'trace' => $this->webservice->trace,
                    'compression' => $this->webservice->compression,
                    'exceptions' => $this->webservice->exceptions,
                    'connection_timeout' => $this->webservice->connectionTimeout,
                    'cache_wsdl' => $this->webservice->cacheWsdl,
                    'stream_context' => stream_context_create([
                        "ssl" => [
                            'local_cert' => $this->settings->certificate->folder . $this->settings->certificate->mixedKey,
                            "verify_peer" => $this->webservice->sslVerifyPeer,
                            "verify_peer_name" => $this->webservice->sslVerifyPeerName,
                        ],
                    ]),
                ];
            }

            try {
                $parent = parent::__construct($this->webservice->wsdl, $options);
            } catch (SoapFault $e) {
                throw new Exception("No momento o sistema da prefeitura está instável ou inoperante, tente novamente mais tarde.\nE - {$e->getMessage()}");
            }

            return $parent;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * seta o xml do soap de entrada
     */
    public function setXML($xmlData): void
    {
        $this->xml = $xmlData;
    }

    /**
     * limpa o xml antes do envio
     */
    private function clearXml(): void
    {
        $remove = ['xmlns:default="http://www.w3.org/2000/09/xmldsig#"', ' standalone="no"', 'default:', ':default', "\n", "\r", "\t", "  "];
        $encode = ['<?xml version="1.0"?>', '<?xml version="1.0" encoding="utf-8"?>', '<?xml version="1.0" encoding="UTF-8"?>', '<?xml version="1.0" encoding="utf-8" standalone="no"?>', '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'];
        $this->xml = str_replace(array_merge($remove, $encode), '', $this->xml);
    }

    //reescreve a chamada ao webservice
    public function __soapCall($function_name = null, $arguments = null, $options = null, $input_headers = null, &$output_headers = null)
    {
        $methodName = str_replace('Request', '', $this->method);

        $soapArguments = $arguments ?? [];

        if ($this->webservice && isset($this->webservice->wsdl)) {
            $soapArguments['location'] = $this->webservice->wsdl;
        }

        return parent::__soapCall($methodName, $soapArguments, $options, $input_headers, $output_headers);
    }

    //monta o cabeçalho e chama o request ao webservice
    public function __doRequest($request, $location, $action, $version, $one_way = 0)
    {
        $this->clearXml();

        //monta a mensagem ao webservice
        $data = '<?xml version="1.0" encoding="utf-8"?>';
        $data .= '<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">';
        $data .= '<S:Body>';
        $data .= '<ns2:' . $this->method . ' xmlns:ns2="http://ws.bhiss.pbh.gov.br">';
        $data .= '<nfseCabecMsg>';
        $data .= '<![CDATA[<cabecalho xmlns="http://www.abrasf.org.br/nfse.xsd" versao="1.00"><versaoDados>1.00</versaoDados></cabecalho>]]>';
        $data .= '</nfseCabecMsg>';
        $data .= '<nfseDadosMsg>';
        $data .= '<![CDATA[' . $this->xml . ']]>';
        $data .= '</nfseDadosMsg>';
        $data .= '</ns2:' . $this->method . '>';
        $data .= '</S:Body>';
        $data .= '</S:Envelope>';

        try {
            $response = parent::__doRequest($data, $location, $action, $version, $one_way);
        } catch (\SoapFault $a) {
            throw new \Exception("Não foi possivel se conectar ao sistema da prefeitura, tente novamente mais tarde.<br>E - {$a->getMessage()} - {$a->getLine()} - {$a->getFile()}");
        } catch (\Exception $b) {
            throw new \Exception("No momento o sistema da prefeitura está instável ou inoperante, tente novamente mais tarde.<br>E - {$b->getMessage()} - {$b->getLine()} - {$b->getFile()}");
        }

        return $response;
    }
}
