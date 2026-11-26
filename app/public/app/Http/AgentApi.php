<?php

namespace App\Http;

use App\Vault\Links;
use App\Vault\Operations;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class AgentApi
{
    public function __construct(private Operations $ops, private Links $links) {}

    public function linkOptions(Request $r)
    {
        return $this->links->options($r->attributes->get('machine'));
    }

    public function linkCreate(Request $r)
    {
        abort_unless($r->isJson(), 415);
        $input = $r->json()->all();
        abort_if(array_diff(array_keys($input), self::LINK_FIELDS), 422);

        return $this->links->createByMachine($r->attributes->get('machine'), $input);
    }

    public function linkStatus(Request $r, string $id)
    {
        return $this->links->status($r->attributes->get('machine'), $id);
    }

    public function linkBurn(Request $r, string $id)
    {
        return $this->links->burnByMachine($r->attributes->get('machine'), $id);
    }

    private const LINK_FIELDS = ['text', 'entry_id', 'lifetime', 'passphrase', 'note', 'request_id'];

    public function catalog(Request $r)
    {
        return $this->ops->catalog($r->attributes->get('machine'), $r->query());
    }

    public function execute(Request $r)
    {
        $d = $r->validate(['entry_id' => 'required|uuid', 'action' => 'required|string|max:100', 'request_id' => 'required|string|max:80', 'arguments' => 'prohibited']);

        return $this->ops->execute($r->attributes->get('machine'), $d['entry_id'], $d['action'], $d['request_id']);
    }

    public function status(Request $r, string $id)
    {
        return $this->ops->status($r->attributes->get('machine'), $id);
    }

    public function cancel(Request $r, string $id)
    {
        return $this->ops->cancel($r->attributes->get('machine'), $id);
    }

    public function mcp(Request $r)
    {
        try {
            return $this->message($r);
        } catch (\Throwable $error) {
            $id = $r->json('id');
            if (! is_string($id) && ! is_int($id)) {
                $id = null;
            }
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof ValidationException ? 422 : 503);
            $code = in_array($status, [400, 415, 422]) ? -32602 : -32000;

            return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $code === -32602 ? 'Invalid parameters' : 'Action unavailable or refused']]);
        }
    }

    private function message(Request $r)
    {
        abort_unless($r->isJson(), 415);
        $message = $r->json()->all();
        abort_unless(($message['jsonrpc'] ?? null) === '2.0' && is_string($message['method'] ?? null), 400);
        if (! array_key_exists('id', $message)) {
            return response('', 202);
        }
        $id = $message['id'];
        abort_unless(is_string($id) || is_int($id) || $id === null, 400);
        $schemas = [
            'vault_search' => ['description' => 'List authorized aliases and actions. Never returns credentials.', 'properties' => ['query' => ['type' => 'string'], 'project_id' => ['type' => 'string'], 'environment_id' => ['type' => 'string'], 'type' => ['type' => 'string'], 'page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer']], 'required' => []],
            'vault_execute' => ['description' => 'Execute a registered action; requires a unique request_id for idempotency.', 'properties' => ['entry_id' => ['type' => 'string'], 'action' => ['type' => 'string'], 'request_id' => ['type' => 'string']], 'required' => ['entry_id', 'action', 'request_id']],
            'vault_status' => ['description' => 'Read your operation status.', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'vault_cancel' => ['description' => 'Cancel your operation.', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'vault_link_options' => ['description' => 'Whether you may create one-time links (Onetime Secret), the lifetimes in seconds you may choose, and the entries whose values you may put in a link, with their field keys.', 'properties' => [], 'required' => []],
            'vault_link_create' => ['description' => 'Create a one-time link to send to a person. text is the content; with entry_id it may name that entry\'s fields as {{field}}, and Vault puts the values in, so you never see them (without text: every field, one per line). With an entry the owner may have to approve first: state "pending", then check vault_link_status. Returns the link while it can be opened. request_id: 8-80 characters, the same one for a retry.', 'properties' => ['text' => ['type' => 'string'], 'entry_id' => ['type' => 'string'], 'lifetime' => ['type' => 'integer'], 'passphrase' => ['type' => 'string'], 'note' => ['type' => 'string'], 'request_id' => ['type' => 'string']], 'required' => ['request_id']],
            'vault_link_status' => ['description' => 'Read the state of your one-time link: pending, active (not opened), previewed, revealed, burned, expired, cancelled or failed; the link while it can be opened.', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'vault_link_burn' => ['description' => 'Destroy your one-time link before it is read.', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
        ];
        // The link tools exist only for an agent the owner allowed one-time links.
        if (! $r->attributes->get('machine')->links) {
            $schemas = array_filter($schemas, fn ($name) => ! str_starts_with($name, 'vault_link_'), ARRAY_FILTER_USE_KEY);
        }
        $method = $message['method'];
        if ($method === 'initialize') {
            $result = ['protocolVersion' => '2025-03-26', 'capabilities' => ['tools' => (object) []], 'serverInfo' => ['name' => 'vault', 'version' => '1.0.0']];
        } elseif ($method === 'ping') {
            $result = (object) [];
        } elseif ($method === 'tools/list') {
            $result = ['tools' => []];
            foreach ($schemas as $name => $s) {
                $result['tools'][] = ['name' => $name, 'description' => $s['description'], 'inputSchema' => ['type' => 'object', 'properties' => (object) $s['properties'], 'required' => $s['required'], 'additionalProperties' => false]];
            }
        } elseif ($method === 'tools/call') {
            $name = $message['params']['name'] ?? '';
            $args = $message['params']['arguments'] ?? [];
            abort_unless(is_string($name) && isset($schemas[$name]) && is_array($args), 422);
            abort_unless(! array_diff(array_keys($args), array_keys($schemas[$name]['properties'])), 422);
            foreach ($schemas[$name]['required'] as $key) {
                abort_unless(isset($args[$key]) && is_string($args[$key]) && strlen($args[$key]) <= 100, 422);
            }
            foreach ($args as $key => $value) {
                $type = $schemas[$name]['properties'][$key]['type'];
                abort_unless($type === 'integer' ? is_int($value) : is_string($value), 422);
            }
            foreach (['id', 'entry_id'] as $key) {
                if (isset($args[$key])) {
                    abort_unless(Str::isUuid($args[$key]), 422);
                }
            }
            $m = $r->attributes->get('machine');
            $data = match ($name) {
                'vault_search' => $this->ops->catalog($m, $args),'vault_execute' => $this->ops->execute($m, $args['entry_id'], $args['action'], $args['request_id']),
                'vault_status' => $this->ops->status($m, $args['id']),'vault_cancel' => $this->ops->cancel($m, $args['id']),
                'vault_link_options' => $this->links->options($m), 'vault_link_create' => $this->links->createByMachine($m, $args),
                'vault_link_status' => $this->links->status($m, $args['id']), 'vault_link_burn' => $this->links->burnByMachine($m, $args['id']),
            };
            $result = ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_THROW_ON_ERROR)]], 'isError' => false];
        } else {
            return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]);
        }

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }
}
