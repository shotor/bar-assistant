<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OAT;
use Kami\Cocktail\OpenAPI as BAO;
use Kami\Cocktail\Models\Bar;
use Kami\Cocktail\Models\User;
use Symfony\Component\Uid\Ulid;
use Kami\Cocktail\Jobs\SetupBar;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Kami\Cocktail\Models\UserRoleEnum;
use Kami\Cocktail\Models\BarStatusEnum;
use Kami\RecipeUtils\UnitConverter\Units;
use Kami\Cocktail\Http\Requests\BarRequest;
use Kami\Cocktail\Http\Resources\BarResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Kami\Cocktail\Http\Resources\BarMembershipResource;

class BarController extends Controller
{
    #[OAT\Get(path: '/bars', tags: ['Bars'], summary: 'Show a list of bars', description: 'Show a list of bars user has access to. Includes bars that user has made and bars he is a member of.')]
    #[OAT\Response(response: 200, description: 'Successful response', content: [
        new BAO\WrapItemsWithData(BAO\Schemas\Bar::class),
    ])]
    public function index(Request $request): JsonResource
    {
        $bars = Bar::select('bars.*')
            ->join('bar_memberships', 'bar_memberships.bar_id', '=', 'bars.id')
            ->where('bar_memberships.user_id', $request->user()->id)
            ->with('createdUser', 'memberships')
            ->get();

        return BarResource::collection($bars);
    }

    #[OAT\Get(path: '/bars/{id}', tags: ['Bars'], summary: 'Show a specific bar', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ])]
    #[OAT\Response(response: 200, description: 'Successful response', content: [
        new BAO\WrapObjectWithData(BAO\Schemas\Bar::class),
    ])]
    #[BAO\NotAuthorizedResponse]
    #[BAO\NotFoundResponse]
    public function show(Request $request, int $id): JsonResource
    {
        $bar = Bar::findOrFail($id);

        if ($request->user()->cannot('show', $bar)) {
            abort(403);
        }

        if (!$bar->slug) {
            $bar->generateSlug();
            $bar->save();
        }

        $bar->load('createdUser', 'updatedUser');

        return new BarResource($bar);
    }

    #[OAT\Post(path: '/bars', tags: ['Bars'], summary: 'Create a new bar', requestBody: new OAT\RequestBody(
        required: true,
        content: [
            new OAT\JsonContent(ref: BAO\Schemas\BarRequest::class),
        ]
    ))]
    #[OAT\Response(response: 201, description: 'Successful response', content: [
        new BAO\WrapObjectWithData(BAO\Schemas\Bar::class),
    ], headers: [
        new OAT\Header(header: 'Location', description: 'URL of the new resource', schema: new OAT\Schema(type: 'string')),
    ])]
    #[BAO\NotAuthorizedResponse]
    public function store(BarRequest $request): JsonResponse
    {
        if ($request->user()->cannot('create', Bar::class)) {
            abort(403, 'You can not create anymore bars');
        }

        $request->validate([
            'slug' => 'nullable|unique:bars,slug',
        ]);

        $inviteEnabled = (bool) $request->post('enable_invites', '0');
        $barOptions = $request->post('options', []);

        $bar = new Bar();
        $bar->name = $request->post('name');
        $bar->subtitle = $request->post('subtitle');
        $bar->description = $request->post('description');
        $bar->created_user_id = $request->user()->id;
        $bar->invite_code = $inviteEnabled ? (string) new Ulid() : null;
        if ($request->post('slug')) {
            $bar->slug = Str::slug($request->post('slug'));
        } else {
            $bar->generateSlug();
        }

        $settings = [];
        if ($defaultUnits = $request->post('default_units')) {
            $settings['default_units'] = Units::tryFrom($defaultUnits)?->value;
        }
        if ($defaultLanguage = $request->post('default_language')) {
            $settings['default_lang'] = $defaultLanguage;
        }
        $bar->settings = $settings;

        $bar->save();

        $request->user()->joinBarAs($bar, UserRoleEnum::Admin);

        SetupBar::dispatch($bar, $request->user(), $barOptions);

        return (new BarResource($bar))
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('bars.show', $bar->id));
    }

    #[OAT\Put(path: '/bars/{id}', tags: ['Bars'], summary: 'Update a bar', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ], requestBody: new OAT\RequestBody(
        required: true,
        content: [
            new OAT\JsonContent(ref: BAO\Schemas\BarRequest::class),
        ]
    ))]
    #[OAT\Response(response: 200, description: 'Successful response', content: [
        new BAO\WrapObjectWithData(BAO\Schemas\Bar::class),
    ])]
    #[BAO\NotAuthorizedResponse]
    #[BAO\NotFoundResponse]
    public function update(int $id, BarRequest $request): JsonResource
    {
        $bar = Bar::findOrFail($id);

        if ($request->user()->cannot('edit', $bar)) {
            abort(403);
        }

        $request->validate([
            'slug' => 'nullable|unique:bars,slug,' . $bar->id,
        ]);

        Cache::forget('ba:bar:' . $bar->id);

        $inviteEnabled = (bool) $request->post('enable_invites', '0');
        if ($inviteEnabled && $bar->invite_code === null) {
            $bar->invite_code = (string) new Ulid();
        } else {
            $bar->invite_code = null;
        }

        $settings = $bar->settings;
        $settings['default_units'] = Units::tryFrom($request->post('default_units') ?? '')?->value;
        $bar->settings = $settings;

        $bar->status = $request->post('status') ?? BarStatusEnum::Active->value;
        $bar->name = $request->post('name');
        $bar->description = $request->post('description');
        $bar->subtitle = $request->post('subtitle');
        $bar->updated_user_id = $request->user()->id;
        $bar->updated_at = now();
        $bar->save();

        return new BarResource($bar);
    }

    #[OAT\Delete(path: '/bars/{id}', tags: ['Bars'], summary: 'Delete a bar', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ])]
    #[OAT\Response(response: 204, description: 'Successful response')]
    #[BAO\NotAuthorizedResponse]
    #[BAO\NotFoundResponse]
    public function delete(Request $request, int $id): Response
    {
        $bar = Bar::findOrFail($id);

        if ($request->user()->cannot('delete', $bar)) {
            abort(403);
        }

        Cache::forget('ba:bar:' . $bar->id);

        $bar->delete();

        return new Response(null, 204);
    }

    #[OAT\Post(path: '/bars/join', tags: ['Bars'], summary: 'Join a bar via invite code', requestBody: new OAT\RequestBody(
        required: true,
        content: [
            new OAT\JsonContent(type: 'object', properties: [
                new OAT\Property(type: 'string', property: 'invite_code', example: '01H8S3VH2HTEB3D893AW8NTBBC'),
            ]),
        ]
    ))]
    #[OAT\Response(response: 200, description: 'Successful response', content: [
        new BAO\WrapObjectWithData(BAO\Schemas\Bar::class),
    ])]
    #[BAO\NotAuthorizedResponse]
    #[BAO\NotFoundResponse]
    public function join(Request $request): JsonResource
    {
        $barToJoin = Bar::where('invite_code', $request->post('invite_code'))->firstOrFail();

        if ($barToJoin->status === BarStatusEnum::Deactivated->value) {
            abort(403);
        }

        $request->user()->joinBarAs($barToJoin, UserRoleEnum::Guest);

        return new BarResource($barToJoin);
    }

    #[OAT\Delete(path: '/bars/{id}/memberships', tags: ['Bars'], summary: 'Leave a bar you are a member of', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ])]
    #[OAT\Response(response: 204, description: 'Successful response')]
    #[BAO\NotFoundResponse]
    public function leave(Request $request, int $id): Response
    {
        $bar = Bar::findOrFail($id);

        $request->user()->leaveBar($bar);

        return new Response(null, 204);
    }

    #[OAT\Delete(path: '/bars/{id}/memberships/{userId}', tags: ['Bars'], summary: 'Remove a member from a bar', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
        new OAT\Parameter(name: 'userId', in: 'path', required: true, description: 'Database id of a user', schema: new OAT\Schema(type: 'integer')),
    ])]
    #[OAT\Response(response: 204, description: 'Successful response')]
    #[BAO\NotFoundResponse]
    #[BAO\NotAuthorizedResponse]
    public function removeMembership(Request $request, int $id, int $userId): Response
    {
        $bar = Bar::findOrFail($id);

        if ($request->user()->cannot('deleteMembership', $bar)) {
            abort(403);
        }

        if ((int) $request->user()->id === (int) $userId) {
            abort(400, 'You cannot remove your own bar membership.');
        }

        $bar->memberships()->where('user_id', $userId)->delete();

        return new Response(null, 204);
    }

    #[OAT\Get(path: '/bars/{id}/memberships', tags: ['Bars'], summary: 'List all members of a bar', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ])]
    #[OAT\Response(response: 200, description: 'Successful response', content: [
        new BAO\WrapObjectWithData(BAO\Schemas\BarMembership::class),
    ])]
    #[BAO\NotAuthorizedResponse]
    #[BAO\NotFoundResponse]
    public function memberships(Request $request, int $id): JsonResource
    {
        $bar = Bar::findOrFail($id);

        if ($request->user()->cannot('show', $bar)) {
            abort(403);
        }

        $bar->load('memberships');

        return BarMembershipResource::collection($bar->memberships);
    }

    #[OAT\Post(path: '/bars/{id}/transfer', tags: ['Bars'], summary: 'Transfer bar ownership', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ], requestBody: new OAT\RequestBody(
        required: true,
        content: [
            new OAT\JsonContent(type: 'object', properties: [
                new OAT\Property(type: 'integer', property: 'user_id', example: 1, description: 'Database id of a user you want to transfer ownership to'),
            ]),
        ]
    ))]
    #[OAT\Response(response: 204, description: 'Successful response')]
    #[BAO\NotAuthorizedResponse]
    #[BAO\NotFoundResponse]
    public function transfer(Request $request, int $id): JsonResponse
    {
        $bar = Bar::findOrFail($id);

        if ($request->user()->cannot('transfer', $bar)) {
            abort(403);
        }

        $newOwner = User::findOrFail((int) $request->post('user_id'));

        $bar->created_user_id = $newOwner->id;
        $bar->save();

        $barOwnership = $newOwner->joinBarAs($bar, UserRoleEnum::Admin);
        $barOwnership->user_role_id = UserRoleEnum::Admin->value; // Needed for existing members
        $barOwnership->save();

        return response()->json(status: 204);
    }

    #[OAT\Post(path: '/bars/{id}/status', tags: ['Bars'], summary: 'Update bar status', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ], requestBody: new OAT\RequestBody(
        required: true,
        content: [
            new OAT\JsonContent(type: 'object', properties: [
                new OAT\Property(ref: BarStatusEnum::class, property: 'status', example: BarStatusEnum::Active->value),
            ]),
        ]
    ))]
    #[OAT\Response(response: 204, description: 'Successful response')]
    #[BAO\NotAuthorizedResponse]
    #[BAO\NotFoundResponse]
    public function toggleBarStatus(Request $request, int $id): JsonResponse
    {
        $bar = Bar::findOrFail($id);

        if ($request->user()->cannot('edit', $bar)) {
            abort(403);
        }

        $newStatus = $request->post('status');

        // Unsubscribed users can only have 1 active bar
        $hasMaxActiveBars = !$request->user()->hasActiveSubscription()
            && $request->user()->ownedBars()->where('status', BarStatusEnum::Active->value)->count() >= 1;

        if ($newStatus === BarStatusEnum::Active->value && $request->user()->can('activate', $bar) && !$hasMaxActiveBars) {
            $bar->status = $newStatus;
            $bar->save();

            return response()->json(status: 204);
        }

        if ($newStatus === BarStatusEnum::Deactivated->value && $request->user()->can('deactivate', $bar)) {
            $bar->status = $newStatus;
            $bar->save();

            return response()->json(status: 204);
        }

        abort(403);
    }
}
