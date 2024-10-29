<?php
/*
Plugin Name: Data Control Plugin
Description: Plugin para controle de dados nas plataformas RD Station e Pipedrive.
Version: 1.5
Author: Douglas
*/

// Definições de configuração
define('DCP_CLIENT_ID', '8a8ec063-6ffa-49b5-bc74-0d1e635dbb89');
define('DCP_CLIENT_SECRET', 'e0da5d7ba01c4ecc965a8afa4b4a74d1');
define('DCP_REDIRECT_URI', 'https://lp.sistemaeso.com.br/wp-json/dcp/v1/callback'); // URL de callback
define('DCP_TOKEN_URL', 'https://api.rd.services/auth/token'); // URL para obtenção do token
define('DCP_API_KEY', '7634f7df7a0c72b02388e9159128c01d6fcc61c8');  // Insira sua chave de API do Pipedrive

// Funções do plugin
class DataControlPlugin {

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('init', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_dcp_send_code', [$this, 'send_verification_code_ajax']);
        add_action('wp_ajax_nopriv_dcp_send_code', [$this, 'send_verification_code_ajax']);
    }

    // Ativação do plugin
    public function activate() {
        add_option('rd_access_token', 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJodHRwczovL2FwaS5yZC5zZXJ2aWNlcyIsInN1YiI6ImZIb2pxR2JBd2NEcGZZeHg2YmQzamZIX085azRsVFZpenFGdE9PM0ZKVHNAY2xpZW50cyIsImF1ZCI6Imh0dHBzOi8vYXBwLnJkc3RhdGlvbi5jb20uYnIvYXBpL3YyLyIsImFwcF9uYW1lIjoiQ29udHJvbGUgZGUgRGFkb3MiLCJleHAiOjE3MzAyMTAyODUsImlhdCI6MTczMDEyMzg4NSwic2NvcGUiOiIifQ.QStkXIBjciUHuaTVLtFw3MEJEX-JbU49Bu0FWXUBWARhC6k0otHUKcWx8J5gMri88asYweozlcVDGW56lQF4nANDFgt8VX1gVCJrgnMYvRNYSUY5M5OkLgP_NUc2Vbyc6LqxzxFHvZc1X0JlebIoLSkLo2bE7PsvzT8YDpxh3vu1NM5WWacYe6N3gfPdMgFiuI8wdz_C_zvPCCfkBRW7k01hjYpCnGHbD39plnosNZ57flWDTNzB0y9c-tNHL0EvhP6u1fKDE1Gq18HoKtRYFwamGllUrhalW8YYSbwmIcW1LKvmTNIcsh36dhC2ZlkLGq1CXfxI97rIxD5fyxo61w');
        add_option('rd_refresh_token', '2_sPuEnnQeerxdLGrFw05gRiZpYfoczMz-bziwVV4bI');
    }

    // Inicialização do plugin
    public function init() {
        add_shortcode('dcp_email_form', [$this, 'render_email_form']);
        add_shortcode('dcp_verification_form', [$this, 'render_verification_form']);

        // Verifica se o método POST está presente e valida o código
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_email'], $_POST['verification_code'])) {
            $this->validate_code();
        }
    }

    // Enqueue scripts
    public function enqueue_scripts() {
        wp_enqueue_script('dcp-ajax-script', plugin_dir_url(__FILE__) . 'dcp-ajax.js', ['jquery'], null, true);
        wp_localize_script('dcp-ajax-script', 'dcp_ajax_object', ['ajax_url' => admin_url('admin-ajax.php')]);
    }

    // Renderiza o formulário de e-mail
    public function render_email_form() {
        ob_start();
        ?>
        <form id="dcp-email-form" method="POST">
            <input type="email" name="user_email" placeholder="Seu e-mail" required>
            <input type="submit" value="Solicitar Código">
        </form>
        <div id="dcp-response-message"></div>
        
        <!-- Formulário de verificação escondido inicialmente -->
        <div id="dcp-verification-form" style="display:none;">
            <form id="dcp-verify-form" method="POST">
                <input type="email" name="user_email" placeholder="Seu e-mail" required>
                <input type="text" name="verification_code" placeholder="Código de Verificação" required>
                <input type="submit" value="Validar Código">
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // Envia o código de verificação via AJAX
    public function send_verification_code_ajax() {
        $email = sanitize_email($_POST['user_email']);
        
        if (is_email($email)) {
            $code = rand(100000, 999999); // Gera o código de 6 dígitos
            set_transient('dcp_verification_code_' . $email, $code, 10 * MINUTE_IN_SECONDS); // Armazena o código temporariamente (10 minutos)

            $subject = 'Seu Código de Verificação';
            $message = 'Seu código de verificação é: ' . $code;

            if (wp_mail($email, $subject, $message)) {
                wp_send_json_success('Código enviado para o e-mail: ' . esc_html($email));
            } else {
                wp_send_json_error('Erro ao enviar o e-mail.');
            }
        } else {
            wp_send_json_error('Endereço de e-mail inválido.');
        }
    }

    // Consultar dados do usuário no RD Station
    public function get_rd_station_user_data($email) {
        $accessToken = get_option('rd_access_token');
        $refreshToken = get_option('rd_refresh_token');

        // Se o access_token estiver expirado, atualize-o
        if ($this->is_token_expired($accessToken)) {
            $tokens = $this->refresh_access_token($refreshToken);
            if (isset($tokens['access_token'])) {
                $accessToken = $tokens['access_token'];
                $refreshToken = $tokens['refresh_token'];
                // Salvar os novos tokens
                update_option('rd_access_token', $accessToken);
                update_option('rd_refresh_token', $refreshToken);
            } else {
                return 'Erro ao atualizar o access token.';
            }
        }

        $url = 'https://api.rd.services/platform/contacts/email:' . urlencode($email);
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);

        if (is_wp_error($response)) {
            return 'Erro ao buscar dados do RD Station.';
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    // Verifica se o token está expirado
    public function is_token_expired($accessToken) {
        // Implementar a lógica de verificação da expiração do token conforme necessário
        return false; // Ajuste a lógica conforme necessário
    }

    // Atualiza o access_token com o refresh_token
    public function refresh_access_token($refreshToken) {
        $response = wp_remote_post(DCP_TOKEN_URL, [
            'body' => json_encode([
                'client_id' => DCP_CLIENT_ID,
                'client_secret' => DCP_CLIENT_SECRET,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return 'Erro ao atualizar access token: ' . $response->get_error_message();
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    // Consultar dados do usuário no Pipedrive
    public function get_pipedrive_user_data($email) {
        $url = 'https://api.pipedrive.com/v1/persons/search?term=' . urlencode($email) . '&api_token=' . DCP_API_KEY;

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return 'Erro ao buscar dados do Pipedrive.';
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    // Função para exibir os dados formatados
    public function exibir_dados_formatados($rd_station_data, $pipedrive_data) {
        // Dados do RD Station formatados
        $rd_station_html = '
        <h2>Dados do RD Station</h2>
        <ul>
            <li><strong>UUID:</strong> ' . $rd_station_data['uuid'] . '</li>
            <li><strong>E-mail:</strong> ' . $rd_station_data['email'] . '</li>
            <li><strong>Nome:</strong> ' . $rd_station_data['name'] . '</li>
            <li><strong>Cargo:</strong> ' . $rd_station_data['job_title'] . '</li>
            <li><strong>Biografia:</strong> ' . $rd_station_data['bio'] . '</li>
            <li><strong>Estado:</strong> ' . $rd_station_data['state'] . '</li>
            <li><strong>Cidade:</strong> ' . $rd_station_data['city'] . '</li>
            <li><strong>Telefone Celular:</strong> ' . $rd_station_data['mobile_phone'] . '</li>
            <li><strong>Telefone Fixo:</strong> ' . $rd_station_data['personal_phone'] . '</li>
            <li><strong>Tags:</strong>
                <ul>';

        foreach ($rd_station_data['tags'] as $tag) {
            $rd_station_html .= '<li>' . $tag . '</li>';
        }

        $rd_station_html .= '
                </ul>
            </li>
            <li><strong>Base Legal:</strong> 
                <ul>
                    <li>Categoria: ' . $rd_station_data['legal_bases'][0]['category'] . '</li>
                    <li>Tipo: ' . $rd_station_data['legal_bases'][0]['type'] . '</li>
                    <li>Status: ' . $rd_station_data['legal_bases'][0]['status'] . '</li>
                </ul>
            </li>
        </ul>';

		// Dados do Pipedrive formatados
		$pipedrive_html = '
		<h2>Dados do Pipedrive</h2>
		<ul>
			<li><strong>ID:</strong> ' . esc_html($pipedrive_data['data']['items'][0]['item']['id'] ?? 'N/A') . '</li>
			<li><strong>Nome:</strong> ' . esc_html($pipedrive_data['data']['items'][0]['item']['name'] ?? 'N/A') . '</li>
			<li><strong>E-mail:</strong> ' . esc_html($pipedrive_data['data']['items'][0]['item']['primary_email'] ?? 'N/A') . '</li>
			<li><strong>Telefone:</strong> ' . esc_html($pipedrive_data['data']['items'][0]['item']['phones'][0] ?? 'N/A') . '</li>
			<li><strong>Endereço:</strong> ' . esc_html(implode(', ', $pipedrive_data['data']['items'][0]['item']['custom_fields'] ?? [])) . '</li>
		</ul>';


        return $rd_station_html . $pipedrive_html;
    }

    // Validação do código de verificação
    public function validate_code() {
        $email = sanitize_email($_POST['user_email']);
        $code = sanitize_text_field($_POST['verification_code']);
        $stored_code = get_transient('dcp_verification_code_' . $email);

        if ($stored_code && $stored_code == $code) {
            // Se o código for válido, buscar dados nas duas plataformas
            $rd_station_data = $this->get_rd_station_user_data($email);
            $pipedrive_data = $this->get_pipedrive_user_data($email);

            // Exibir dados formatados
            echo $this->exibir_dados_formatados($rd_station_data, $pipedrive_data);
        } else {
            echo 'Código de verificação inválido ou expirado.';
        }
    }
}

// Inicializa o plugin
new DataControlPlugin();
