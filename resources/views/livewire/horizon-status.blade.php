<div>
    <span title="{{ $horizonActive ? 'Queue Online' : 'Queue Offline' }}" wire:poll.2s>
        <i class="fas fa-circle {{ $horizonActive ? 'text-success' : 'text-danger' }}"></i>
{{--        <small class="ms-1">--}}
{{--            {{ $horizonActive ? 'Queue Online' : 'Queue Offline' }}--}}
{{--        </small>--}}
    </span>
</div>
