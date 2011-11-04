<?php

class Cnpj {
    /*
     *  Cria uma requisição no site da receita
     *  Esse passo é necessário para abrir uma sessão no servidor.
     *  e precisa ser feito antes de fazer a requisição da imagem
     */

    private function doRequest($domain, $path, $cookies=array(), $postFields = array()) {
        if (!$postFields)
            $meth = HttpRequest::METH_GET;
        else
            $meth = HttpRequest::METH_POST;        		
        $r = new HttpRequest($domain . $path, $meth);
        $r->setOptions(array('redirect' => 10));
        if ($postFields) {
			$postFields = array_merge(array(
										'origem' => 'comprovante',
										'search_type' => 'cnpj',
										'submit1' => 'Consultar'),
										 $postFields);						
            $r->addPostFields($postFields);
        }
        $r->setCookies($cookies);
        try {
            $reponse = $r->send();
        } catch (Exception $e) {
            $reponse = false;
        }
        return $reponse;
    }

    /*
     * Retorna um array com os cookies da requisição
     */

    private function setResponseCookie($response_cookie_header) {
        $response_cookie = http_parse_cookie($response_cookie_header);
        if ($response_cookie) {
            foreach ($response_cookie->cookies as $key => $value) {
                setcookie($key, $value);
            }
            return $response_cookie->cookies;
        }else
            return false;
    }

    /*
     * Salva a imagem do captcha da recita federal e o cookie vinculado a ele
     */

    public function saveCaptcha() {		
        $domain = 'http://www.receita.fazenda.gov.br';
        $path = '/pessoajuridica/cnpj/cnpjreva/Cnpjreva_Solicitacao2.asp';
        $response = $this->doRequest($domain, $path);
        if ($response)
            $sessionCookie = $this->setResponseCookie($response->getHeader('Set-Cookie'));

        $captcha_path = '/scripts/srf/intercepta/captcha.aspx?opt=image';
        $response = $this->doRequest($domain, $captcha_path,$sessionCookie);
        if ($response)
            $captchaCookie = $this->setResponseCookie($response->getHeader('Set-Cookie'));
        else
            return false;
        /*
         * O cookie possui '/' no seu nome , vamos trocar eles por '_'
         * para salvar o arquivo com seu nome.
         * Estou salvando a imagem com nome diferente para criar um cache,
         * assim não é necessário criar novamente o arquivo se ele já existe
         * mas você pode usar um nome estático como receita.gif
         */
        $filename = str_replace(array('/',' '), '_', $captchaCookie['cookieCaptcha'] . '.gif');
        if (!file_exists($filename)) {
            $fp = fopen($filename, 'w');
            fwrite($fp, $response->getBody());
            fclose($fp);
        }
        return $filename;
    }

    function getResponse($posted_fields, $cookies) {
        $domain = 'http://www.receita.fazenda.gov.br';
        $path = '/pessoajuridica/cnpj/cnpjreva/valida.asp';
        $response = $this->doRequest($domain, $path,$cookies,$posted_fields);
        //Eles adicionam esse cabeçalho apenas quando a pesquisa falha

        if ($response->getHeader('Pragma')) {
            return false;
        } else {
			return $response->getBody();    
        }
        
    }
    
    public function parseCnpj($response) {		
        libxml_use_internal_errors(true);        
            $dom = new DOMDocument();
            $dom->loadHTML($response);
            $nodeList = $dom->getElementsByTagName('font');
            $cnpj_data = array(
                0 => null, //'DATA_DE_ABERTURA'
                1 => null, //'NOME_EMPRESARIAL'
                2 => null, //'NOME_FANTASIA'
                3 => null, //'ATIVIDADE_ECON_PRINCIPAL'
                4 => array(), //'ATIVIDADES_ECON_SECUNDARIA'
                5 => null, //'NATUREZA_JURIDICA'
                6 => null, //'LOGRADOURO'
                7 => null, //'NUMERO'
                8 => null, //'COMPLEMENTO'
                9 => null, //'CEP'
                10 => null, //'BAIRRO'
                11 => null, //'MUNICIPIO'
                12 => null, //'ESTADO'
                13 => null, //'SITUACAO_CADASTRAL'
                14 => null, //'DATA_SITUACAO_CADASTRAL'
                15 => null, //'MOTIVO_SITUCAO_CADASTRAL'
                16 => null, //SITUACAO_CADASTRAL
                17 => null //SITUCAO_ESPECIAL
            );
            $c = 0;
            $key = 0;
            foreach ($nodeList as $domElement) {
                if ($c > 7) {
                    $domNode = $dom->importNode($domElement, true);
                    if ($domNode->getElementsByTagName('b')->length > 0) {
                        if ($key != 4) {
                            $cnpj_data[$key] = trim($domElement->nodeValue);
                        } else {
                            array_push($cnpj_data[$key], trim($domElement->nodeValue));
                        }
                    } else {
                        $key++;
                    }
                }
                $c++;
            }
            header('Content-type: application/json');
            return json_encode($cnpj_data);
    }

}
?>
<?php
$cnpj = new Cnpj;
if (!$_POST){
	$image =  $cnpj->saveCaptcha();
	echo '<img src="'.$image.'"  alt="captcha" />';
}
else{
	$reponse = $cnpj->getResponse($_POST,$_COOKIE);
	if ($reponse){
		echo $cnpj->parseCnpj($reponse);
	}
	else{
		$image =  $cnpj->saveCaptcha();
		echo '<br />Erro, por favor tente novamente <br />';
		echo '<img src="'.$image.'"  alt="captcha" />';
	}			
}
?>
