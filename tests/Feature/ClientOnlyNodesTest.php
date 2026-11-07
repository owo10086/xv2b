<?php

namespace Tests\Feature;

use App\Models\ServerGroup;
use App\Models\ServerShadowsocks;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * 节点渠道：仅「普通」只发普通订阅，仅「客户端」只发客户端订阅，双标签或无渠道标签共享。
 * 三个节点出口：订阅下载按可选登录凭据选择渠道；旧 App 配置走普通渠道；网页节点列表包含全部。
 *
 * 用例连的是测试库，每个用例在事务里执行、结束后回滚。
 * 每个用例新建自己的权限组，库里已有的节点不会混进结果。
 * 节点地址由节点名拼成，用来在各种订阅格式的内容里认出节点。
 */
class ClientOnlyNodesTest extends TestCase
{
    use DatabaseTransactions;

    private $group;

    protected function setUp(): void
    {
        parent::setUp();
        // 鉴权失败用例断言 JSON 错误响应，显式声明响应类型。
        $this->withHeaders(['Accept' => 'application/json']);
        // 测试站点可能开着信息节点；只有专门验证它的用例才开启，避免设置影响节点断言。
        config(['v2board.show_info_to_server_enable' => 0]);
        $this->group = $this->makeGroup();
    }

    public function testSubscribeWithoutCredentialsOmitsClientNodes()
    {
        $this->makeNode('pub', null, 1);
        $this->makeNode('cli', ['客户端'], 2);
        $user = $this->makeUser();

        $this->assertSame([self::host('pub')], $this->subscribedHosts($user, 'meta'));
    }

    /** @dataProvider ownerFormatsAndCredentialLocations */
    public function testSubscribeWithOwnerCredentialsIncludesClientNodes(string $flag, string $location)
    {
        $this->makeNode('pub', null, 1);
        $this->makeNode('cli', ['客户端'], 2);
        $user = $this->makeUser();
        $auth = $this->authData($user);

        $this->assertSame(
            [self::host('pub'), self::host('cli')],
            $this->subscribedHosts($user, $flag, ...$this->credentialOptions($auth, $location))
        );
    }

    /** @dataProvider channelTags */
    public function testChannelTagsControlSubscriptionAppAndWebOutputs($tags, bool $ordinary, bool $client)
    {
        $this->makeNode('target', $tags, 1);
        $user = $this->makeUser();
        $ordinaryHosts = $ordinary ? [self::host('target')] : [];
        $clientHosts = $client ? [self::host('target')] : [];

        $this->assertSame($ordinaryHosts, $this->subscribedHosts($user, 'meta'));
        $this->assertSame(
            $clientHosts,
            $this->subscribedHosts($user, 'meta', ['Authorization' => $this->authData($user)])
        );
        $app = $this->get('/api/v1/client/app/getConfig?' . http_build_query(['token' => $user->token]));
        $app->assertOk();
        $this->assertSame($ordinaryHosts, array_column($this->clashProxies($app), 'server'));
        $this->assertSame([['host' => self::host('target'), 'tags' => $tags]], $this->webNodes($user));
    }

    public static function channelTags(): array
    {
        return [
            '普通专属' => [['普通'], true, false],
            '客户端专属' => [['客户端'], false, true],
            '双标签共享' => [['普通', '客户端'], true, true],
            '双标签反序' => [['客户端', '普通'], true, true],
            '空标签共享' => [[], true, true],
            '缺失标签共享' => [null, true, true],
            '展示标签共享' => [['香港', '高速'], true, true],
            '普通首尾空白' => [[" \t普通\n"], true, false],
            '客户端首尾空白' => [['　客户端　'], false, true],
            '双标签首尾空白' => [['　普通　', " \t客户端\n"], true, true],
            '普通重复' => [['普通', '普通'], true, false],
            '客户端重复' => [['客户端', '客户端'], false, true],
            '双标签重复' => [['香港', '普通', '客户端', '普通', '客户端'], true, true],
            '近似标签共享' => [['普通A', '非普通', '客户端专用'], true, true],
            '整段标签不拆分' => [['普通,客户端'], true, true],
            '普通及整段标签' => [['普通', '普通,客户端'], true, false],
            '客户端及整段标签' => [['客户端', '普通,客户端'], false, true],
            '公开是展示标签' => [['公开'], true, true],
            '公开不覆盖普通' => [['普通', '公开'], true, false],
            '公开不覆盖客户端' => [['客户端', '公开'], false, true],
            '非数组沿用共享' => ['普通', true, true],
            '对象值普通沿用匹配' => [['label' => '普通'], true, false],
            '对象值客户端沿用匹配' => [['label' => '客户端'], false, true],
            '非字符串不影响普通' => [['普通', null, 6, false], true, false],
            '非字符串不影响共享' => [['普通', null, 6, '客户端'], true, true],
        ];
    }

    /** @dataProvider ownerFormatsAndCredentialLocations */
    public function testChannelSelectionAcrossFormatsAndCredentials(string $flag, string $location)
    {
        $this->makeNode('shared', ['香港'], 1);
        $this->makeNode('ordinary', ['普通'], 2);
        $this->makeNode('client', ['客户端'], 3);
        $this->makeNode('dual', ['客户端', '普通'], 4);
        $owner = $this->makeUser();
        $otherGroup = $this->makeGroup();
        $this->makeNode('other', ['普通', '客户端'], 5, ['group_id' => [(string)$otherGroup->id]]);
        $other = $this->makeUser(['group_id' => $otherGroup->id]);
        $ordinaryHosts = [self::host('shared'), self::host('ordinary'), self::host('dual')];

        $this->assertSame($ordinaryHosts, $this->subscribedHosts($owner, $flag));
        $this->assertSame(
            [self::host('shared'), self::host('client'), self::host('dual')],
            $this->subscribedHosts($owner, $flag, ...$this->credentialOptions($this->authData($owner), $location))
        );
        $this->assertSame(
            $ordinaryHosts,
            $this->subscribedHosts($owner, $flag, ...$this->credentialOptions($this->authData($other), $location))
        );
    }

    /** @dataProvider credentialLocations */
    public function testFourCredentialOutcomesUseSameSubscriptionToken(string $location)
    {
        $this->makeNode('pub', null, 1);
        $this->makeNode('cli', ['客户端'], 2);
        $otherGroup = $this->makeGroup();
        $this->makeNode('other-pub', null, 3, ['group_id' => [(string)$otherGroup->id]]);
        $owner = $this->makeUser();
        $other = $this->makeUser(['group_id' => $otherGroup->id]);
        $publicHosts = [self::host('pub')];

        // 同一个订阅令牌依次验证四种结果，别人的凭据也不能替换令牌主人的权限组。
        $this->assertSame($publicHosts, $this->subscribedHosts($owner, 'meta'));
        $this->subscribe($owner, 'meta', ...$this->credentialOptions('not.a.valid-credential', $location))
            ->assertStatus(403)
            ->assertJsonPath('message', '未登录或登陆已过期');
        $this->assertSame(
            [self::host('pub'), self::host('cli')],
            $this->subscribedHosts($owner, 'meta', ...$this->credentialOptions($this->authData($owner), $location))
        );
        $this->assertSame(
            $publicHosts,
            $this->subscribedHosts($owner, 'meta', ...$this->credentialOptions($this->authData($other), $location))
        );
        $this->assertSame($publicHosts, $this->subscribedHosts($owner, 'meta'));
    }

    /** @dataProvider credentialLocations */
    public function testRemovedAllSessionsRejectsPreviouslyCachedCredentials(string $location)
    {
        $this->makeNode('pub', null, 1);
        $this->makeNode('cli', ['客户端'], 2);
        $user = $this->makeUser();
        $auth = $this->authData($user);

        // 先走账号接口校验，让凭据进入现有的一小时缓存，再移除全部会话。
        $this->get('/api/v1/user/checkLogin', ['Authorization' => $auth])->assertOk();
        $this->assertSame(
            [self::host('pub'), self::host('cli')],
            $this->subscribedHosts($user, 'meta', ...$this->credentialOptions($auth, $location))
        );
        (new AuthService($user))->removeAllSession();

        $this->subscribe($user, 'meta', ...$this->credentialOptions($auth, $location))
            ->assertStatus(403)
            ->assertJsonPath('message', '未登录或登陆已过期');
        $this->get('/api/v1/user/checkLogin', ['Authorization' => $auth])
            ->assertStatus(403)
            ->assertJsonPath('message', '未登录或登陆已过期');
        $this->assertSame([self::host('pub')], $this->subscribedHosts($user, 'meta'));
    }

    /** @dataProvider unavailableAccountsAndCredentialLocations */
    public function testInvalidCredentialsTakePrecedenceForUnavailableAccounts(array $values, string $location)
    {
        $user = $this->makeUser($values);

        $this->subscribe($user, 'meta', ...$this->credentialOptions('not.a.valid-credential', $location))
            ->assertStatus(403)
            ->assertJsonPath('message', '未登录或登陆已过期');
    }

    /** @dataProvider unavailableAccountsAndCredentialLocations */
    public function testValidOwnerCredentialsKeepUnavailableAccountResponse(array $values, string $location)
    {
        $this->makeNode('pub', null, 1);
        $this->makeNode('cli', ['客户端'], 2);
        $user = $this->makeUser($values);
        $withoutCredentials = $this->subscribe($user, 'meta');
        $withoutCredentials->assertOk();
        $this->assertSame('', $withoutCredentials->getContent());

        $withCredentials = $this->subscribe(
            $user,
            'meta',
            ...$this->credentialOptions($this->authData($user), $location)
        );
        $this->assertSame($withoutCredentials->getStatusCode(), $withCredentials->getStatusCode());
        $this->assertSame($withoutCredentials->getContent(), $withCredentials->getContent());
    }

    public function testAuthDataParameterTakesPrecedenceOverAuthorizationHeader()
    {
        $this->makeNode('pub', null, 1);
        $this->makeNode('cli', ['客户端'], 2);
        $owner = $this->makeUser();
        $ownerAuth = $this->authData($owner);
        $otherAuth = $this->authData($this->makeUser());

        $this->subscribe($owner, 'meta', ['Authorization' => $ownerAuth], ['auth_data' => 'not.a.valid-credential'])
            ->assertStatus(403)
            ->assertJsonPath('message', '未登录或登陆已过期');
        $this->assertSame(
            [self::host('pub')],
            $this->subscribedHosts($owner, 'meta', ['Authorization' => $ownerAuth], ['auth_data' => $otherAuth])
        );
        $this->assertSame(
            [self::host('pub'), self::host('cli')],
            $this->subscribedHosts($owner, 'meta', ['Authorization' => 'not.a.valid-credential'], ['auth_data' => $ownerAuth])
        );
    }

    public function testOwnerCredentialsStillRespectGroupAndVisibility()
    {
        $otherGroup = $this->makeGroup();
        $this->makeNode('pub', null, 1);
        $this->makeNode('cli-visible', ['客户端'], 2);
        $this->makeNode('cli-other-group', ['客户端'], 3, ['group_id' => [(string)$otherGroup->id]]);
        $this->makeNode('cli-hidden', ['客户端'], 4, ['show' => 0]);
        $user = $this->makeUser();

        $this->assertSame(
            [self::host('pub'), self::host('cli-visible')],
            $this->subscribedHosts($user, 'meta', ['Authorization' => $this->authData($user)])
        );
    }

    /** @dataProvider ownerFormatsAndCredentialLocations */
    public function testUntaggedSubscriptionUnchangedWithValidCredentials(string $flag, string $location)
    {
        $this->makeNode('node-a', null, 1);
        $this->makeNode('node-b', [], 2);
        $user = $this->makeUser();
        $withoutCredentials = $this->subscribe($user, $flag);
        $withoutCredentials->assertOk();
        $withCredentials = $this->subscribe($user, $flag, ...$this->credentialOptions($this->authData($user), $location));
        $withCredentials->assertOk();

        $this->assertSame($withoutCredentials->getContent(), $withCredentials->getContent());
        $this->assertSame(
            [self::host('node-a'), self::host('node-b')],
            $this->subscribedHosts($user, $flag)
        );
    }

    public function testOwnerInfoNodesCopyFirstAvailableClientNode()
    {
        config(['v2board.show_info_to_server_enable' => 1]);
        $this->makeNode('cli-first', ['客户端'], 1);
        $this->makeNode('pub-second', null, 2);
        $user = $this->makeUser();
        $response = $this->subscribe($user, 'meta', ['Authorization' => $this->authData($user)]);
        $response->assertOk();
        $proxies = $this->clashProxies($response);

        $this->assertStringStartsWith('剩余流量：', $proxies[0]['name']);
        $this->assertContains('套餐到期：' . date('Y-m-d', $user->expired_at), array_column($proxies, 'name'));
        $this->assertSame(
            [self::host('cli-first'), self::host('cli-first'), self::host('cli-first'), self::host('pub-second')],
            array_column($proxies, 'server')
        );
    }

    public function testOwnerInfoNodesSkipOrdinaryNodes()
    {
        config(['v2board.show_info_to_server_enable' => 1]);
        $this->makeNode('ordinary-first', ['普通'], 1);
        $this->makeNode('client-second', ['客户端'], 2);
        $this->makeNode('shared-third', null, 3);
        $user = $this->makeUser();
        $response = $this->subscribe($user, 'meta', ['Authorization' => $this->authData($user)]);
        $response->assertOk();
        $proxies = $this->clashProxies($response);

        $this->assertStringStartsWith('剩余流量：', $proxies[0]['name']);
        $this->assertSame(
            [self::host('client-second'), self::host('client-second'), self::host('client-second'), self::host('shared-third')],
            array_column($proxies, 'server')
        );
    }

    public function testNoOwnerInfoNodesWhenOnlyOrdinaryNodes()
    {
        config(['v2board.show_info_to_server_enable' => 1]);
        $this->makeNode('ordinary-a', ['普通'], 1);
        $this->makeNode('ordinary-b', ['普通', '香港'], 2);
        $user = $this->makeUser();
        $auth = $this->authData($user);

        foreach (['meta', 'v2rayn', 'shadowrocket'] as $flag) {
            $this->assertSame([], $this->subscribedHosts($user, $flag, ['Authorization' => $auth]), $flag);
        }
    }

    public function testAppConfigIgnoresOptionalLoginCredentials()
    {
        $this->makeNode('pub', null, 1);
        $this->makeNode('cli', ['客户端'], 2);
        $this->makeNode('ordinary', ['普通'], 3);
        $this->makeNode('dual', ['普通', '客户端'], 4);
        $this->makeNode('unsupported', ['普通'], 5, ['cipher' => '2022-blake3-aes-128-gcm']);
        $user = $this->makeUser();

        foreach ([$this->authData($user), 'not.a.valid-credential'] as $auth) {
            foreach (['header', 'parameter'] as $location) {
                [$headers, $parameters] = $this->credentialOptions($auth, $location);
                $query = http_build_query(array_merge(['token' => $user->token], $parameters));
                $response = $this->get('/api/v1/client/app/getConfig?' . $query, $headers);
                $response->assertOk();
                $config = Yaml::parse($response->getContent());
                $this->assertSame(
                    [self::host('pub'), self::host('ordinary'), self::host('dual')],
                    array_column($config['proxies'], 'server')
                );
            }
        }
    }

    public static function credentialLocations(): array
    {
        return ['请求头' => ['header'], '请求参数' => ['parameter']];
    }

    public static function ownerFormatsAndCredentialLocations(): array
    {
        return [
            'Clash Meta 请求头' => ['meta', 'header'],
            'Clash Meta 请求参数' => ['meta', 'parameter'],
            'v2rayN 请求头' => ['v2rayn', 'header'],
            'v2rayN 请求参数' => ['v2rayn', 'parameter'],
        ];
    }

    public static function unavailableAccountsAndCredentialLocations(): array
    {
        return [
            '过期账号 请求头' => [['expired_at' => time() - 86400], 'header'],
            '过期账号 请求参数' => [['expired_at' => time() - 86400], 'parameter'],
            '封禁账号 请求头' => [['banned' => 1], 'header'],
            '封禁账号 请求参数' => [['banned' => 1], 'parameter'],
        ];
    }

    public function testClientTagMatching()
    {
        $this->makeNode('pub-null', null, 1);
        $this->makeNode('pub-empty', [], 2);
        $this->makeNode('pub-suffix', ['客户端A'], 3);
        $this->makeNode('pub-longer', ['客户端专用'], 4);
        $this->makeNode('pub-prefix', ['非客户端'], 5);
        $this->makeNode('pub-other', ['香港', '高速'], 6);
        $notList = $this->makeNode('pub-not-list', null, 7);
        DB::table('v2_server_shadowsocks')->where('id', $notList->id)->update(['tags' => '"客户端"']);
        $this->makeNode('cli-single', ['客户端'], 8);
        $this->makeNode('cli-multi', ['香港', '客户端'], 9);
        $this->makeNode('cli-space', [' 客户端 '], 10);
        $this->makeNode('cli-fullwidth-space', ['　客户端　'], 11);
        $user = $this->makeUser();

        $expected = array_map(function ($name) {
            return self::host($name);
        }, ['pub-null', 'pub-empty', 'pub-suffix', 'pub-longer', 'pub-prefix', 'pub-other', 'pub-not-list']);
        $this->assertSame($expected, $this->subscribedHosts($user, 'meta'));
    }

    public function testFiltersConsistentlyAcrossFormats()
    {
        $this->makeNode('pub-a', ['香港'], 1);
        $this->makeNode('cli', ['客户端'], 2);
        $this->makeNode('pub-b', null, 3);
        $user = $this->makeUser();
        $expected = [self::host('pub-a'), self::host('pub-b')];

        foreach (['meta', 'v2rayn', 'shadowrocket'] as $flag) {
            $this->assertSame($expected, $this->subscribedHosts($user, $flag), $flag);
        }
    }

    public function testInfoNodesCopyFirstPublicNode()
    {
        config(['v2board.show_info_to_server_enable' => 1]);
        $this->makeNode('cli-first', ['客户端'], 1);
        $this->makeNode('pub-second', null, 2);
        $user = $this->makeUser();

        $proxies = $this->clashProxies($this->subscribe($user, 'meta'));

        $names = array_column($proxies, 'name');
        $this->assertStringStartsWith('剩余流量：', $names[0]);
        $this->assertContains('套餐到期：' . date('Y-m-d', $user->expired_at), $names);
        $this->assertSame(
            array_fill(0, count($proxies), self::host('pub-second')),
            array_column($proxies, 'server')
        );
    }

    public function testNoInfoNodesWhenOnlyClientNodes()
    {
        config(['v2board.show_info_to_server_enable' => 1]);
        $this->makeNode('cli-a', ['客户端'], 1);
        $this->makeNode('cli-b', ['香港', '客户端'], 2);
        $user = $this->makeUser();

        foreach (['meta', 'v2rayn', 'shadowrocket'] as $flag) {
            $this->assertSame([], $this->subscribedHosts($user, $flag), $flag);
        }
    }

    public function testAppConfigOnlyHasPublicNodes()
    {
        $this->makeNode('pub', null, 1);
        $this->makeNode('cli', ['客户端'], 2);
        $user = $this->makeUser();

        $response = $this->get("/api/v1/client/app/getConfig?token={$user->token}");

        $response->assertOk();
        $config = Yaml::parse($response->getContent());
        $this->assertSame([self::host('pub')], array_column($config['proxies'], 'server'));
    }

    public function testAppConfigWithoutTokenStillRejected()
    {
        $this->get('/api/v1/client/app/getConfig')
            ->assertStatus(403)
            ->assertJson(['message' => 'token is null']);
    }

    public function testWebNodeListIncludesClientNodesWithTags()
    {
        $this->makeNode('pub', ['香港'], 1);
        $this->makeNode('cli', [' 客户端 ', '高速'], 2);
        $user = $this->makeUser();

        $this->assertSame([
            ['host' => self::host('pub'), 'tags' => ['香港']],
            ['host' => self::host('cli'), 'tags' => [' 客户端 ', '高速']],
        ], $this->webNodes($user));
    }

    public function testClientNodesStillRespectGroupAndVisibility()
    {
        $otherGroup = $this->makeGroup();
        $this->makeNode('cli-visible', ['客户端'], 1);
        $this->makeNode('cli-other-group', ['客户端'], 2, ['group_id' => [(string)$otherGroup->id]]);
        $this->makeNode('cli-hidden', ['客户端'], 3, ['show' => 0]);
        $user = $this->makeUser();

        $this->assertSame([self::host('cli-visible')], array_column($this->webNodes($user), 'host'));
    }

    public function testUntaggedNodesUnchangedOnAllOutputs()
    {
        $this->makeNode('node-a', null, 1);
        $this->makeNode('node-b', ['香港'], 2);
        $user = $this->makeUser();
        $expected = [self::host('node-a'), self::host('node-b')];

        $this->assertSame($expected, $this->subscribedHosts($user, 'meta'));
        $appConfig = Yaml::parse($this->get("/api/v1/client/app/getConfig?token={$user->token}")->getContent());
        $this->assertSame($expected, array_column($appConfig['proxies'], 'server'));
        $this->assertSame($expected, array_column($this->webNodes($user), 'host'));
    }

    public function testUnavailableAccountSubscribeUnchanged()
    {
        $this->makeNode('pub', null, 1);
        $this->makeNode('cli', ['客户端'], 2);
        $expired = $this->makeUser(['expired_at' => time() - 86400]);
        $banned = $this->makeUser(['banned' => 1]);

        foreach ([$expired, $banned] as $user) {
            $response = $this->subscribe($user, 'meta');
            $response->assertOk();
            $this->assertSame('', $response->getContent());
        }
    }

    private function makeGroup(): ServerGroup
    {
        $group = new ServerGroup();
        $group->name = '测试组-' . Helper::guid();
        $group->save();
        return $group;
    }

    private function makeUser(array $values = []): User
    {
        $user = new User();
        $user->email = Helper::guid() . '@test.local';
        $user->password = password_hash('test123456', PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->group_id = $this->group->id;
        $user->transfer_enable = 100 * 1073741824;
        $user->expired_at = time() + 30 * 86400;
        foreach ($values as $field => $value) {
            $user->{$field} = $value;
        }
        $user->save();
        return $user;
    }

    private function makeNode(string $name, $tags, int $sort, array $values = []): ServerShadowsocks
    {
        $node = new ServerShadowsocks();
        $node->name = $name;
        $node->group_id = [(string)$this->group->id];
        $node->tags = $tags;
        $node->rate = '1';
        $node->host = self::host($name);
        $node->port = '443';
        $node->server_port = 443;
        $node->cipher = 'aes-128-gcm';
        $node->show = 1;
        $node->sort = $sort;
        foreach ($values as $field => $value) {
            $node->{$field} = $value;
        }
        $node->save();
        return $node;
    }

    private static function host(string $name): string
    {
        return "{$name}.test.invalid";
    }

    private function authData(User $user): string
    {
        return (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
    }

    // 每次请求单独传凭据，不让请求头残留到后面的无凭据请求里。
    private function credentialOptions(string $auth, string $location): array
    {
        return $location === 'parameter' ? [[], ['auth_data' => $auth]] : [['Authorization' => $auth], []];
    }

    private function subscribe(User $user, string $flag, array $headers = [], array $parameters = [])
    {
        $query = http_build_query(array_merge(['token' => $user->token, 'flag' => $flag], $parameters));
        return $this->get('/api/v1/client/subscribe?' . $query, $headers);
    }

    // 按出现顺序取订阅配置里的节点地址：Clash Meta 读节点列表，其余格式解出 base64 后逐行取 @ 后面的主机
    private function subscribedHosts(User $user, string $flag, array $headers = [], array $parameters = []): array
    {
        $response = $this->subscribe($user, $flag, $headers, $parameters);
        $response->assertOk();
        if ($flag === 'meta') {
            return array_column($this->clashProxies($response), 'server');
        }
        $hosts = [];
        foreach (explode("\n", base64_decode($response->getContent())) as $line) {
            if (preg_match('#^[a-z0-9]+://[^@]+@([^:/?\#]+):#', $line, $matches)) {
                $hosts[] = $matches[1];
            }
        }
        return $hosts;
    }

    private function clashProxies($response): array
    {
        $config = Yaml::parse($response->getContent());
        return array_map(function ($proxy) {
            return ['name' => $proxy['name'], 'server' => $proxy['server']];
        }, $config['proxies'] ?? []);
    }

    private function webNodes(User $user): array
    {
        $auth = (new AuthService($user))->generateAuthData(Request::create('/'));
        // 登录凭据只带在这一次请求上，不让它跟到后面的订阅下载请求里
        $response = $this->get('/api/v1/user/server/fetch', ['Authorization' => $auth['auth_data']]);
        $response->assertOk();
        return array_map(function ($node) {
            return ['host' => $node['host'], 'tags' => $node['tags']];
        }, $response->json('data'));
    }
}
