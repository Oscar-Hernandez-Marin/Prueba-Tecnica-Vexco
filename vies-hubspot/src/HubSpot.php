<?php
declare(strict_types=1);

namespace ViesSync;
final class HubSpot
{
    public function __construct(private Http $http, private Config $cfg)
    {
    }
    public function listCompanies(?string $after, array $properties): array
    {
        $query = [
            'limit' => 50,
            'archived' => 'false',
            'properties' => implode(',', $properties),
            'propertiesWithHistory' => $this->cfg->sourceProp,
        ];
        if ($after !== null) {
            $query['after'] = $after;
        }
        $res = $this->call('GET', '/crm/v3/objects/companies', null, $query);
        if ($res['status'] !== 200) {
            $this->fail($res, 'listar empresas');
        }
        return [$res['body']['results'] ?? [], $res['body']['paging']['next']['after'] ?? null];
    }

    public function updateCompany(string $id, array $properties): void
    {
        $res = $this->call('PATCH', '/crm/v3/objects/companies/' . rawurlencode($id), ['properties' => $properties]);
        if ($res['status'] !== 200) {
            $this->fail($res, "actualizar empresa $id");
        }
    }

    public function propertyExists(string $name): bool
    {
        $res = $this->call('GET', '/crm/v3/properties/companies/' . rawurlencode($name));
        if ($res['status'] === 200) {
            return true;
        }
        if ($res['status'] === 404) {
            return false;
        }
        $this->fail($res, "consultar propiedad $name");
    }

    public function ensureGroup(string $name, string $label): bool
    {
        $res = $this->call('POST', '/crm/v3/properties/companies/groups', [
            'name' => $name,
            'label' => $label,
            'displayOrder' => -1,
        ]);
        return $this->createdOrExists($res, "crear grupo $name");
    }

    public function ensureProperty(string $name, string $label, string $group): bool
    {
        $res = $this->call('POST', '/crm/v3/properties/companies', [
            'name' => $name,
            'label' => $label,
            'type' => 'string',
            'fieldType' => 'text',
            'groupName' => $group,
            'description' => 'Validación VIES (ejercicio técnico). Escrita por script; no editar a mano.',
        ]);
        return $this->createdOrExists($res, "crear propiedad $name");
    }

    private function createdOrExists(array $res, string $ctx): bool
    {
        if ($res['status'] === 201 || $res['status'] === 200) {
            return true;
        }
        $msg = (string) ($res['body']['message'] ?? '');
        if ($res['status'] === 409 || ($res['status'] === 400 && stripos($msg, 'already exist') !== false)) {
            return false;
        }
        $this->fail($res, $ctx);
    }

    private function call(string $method, string $path, ?array $json = null, array $query = []): array
    {
        $url = $this->cfg->hubspotBase . $path . ($query ? '?' . http_build_query($query) : '');
        return $this->http->request($method, $url, ['Authorization: Bearer ' . $this->cfg->token], $json);
    }

    private function fail(array $res, string $ctx): never
    {
        $msg = $res['body']['message'] ?? substr($res['raw'], 0, 300);
        $status = $res['status'];
        if ($status === 401) {
            throw new HttpException("HubSpot 401 al $ctx: token inválido o caducado. $msg", true);
        }
        if ($status === 403) {
            throw new HttpException("HubSpot 403 al $ctx: faltan scopes en la service key. $msg", true);
        }
        throw new HttpException("HubSpot $status al $ctx: $msg");
    }
}
