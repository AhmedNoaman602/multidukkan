@props(['title' => null, 'padding' => true])

<div class="card">
    @if($title)
    <div class="card-header">
        <h3 class="card-title">{{ $title }}</h3>
        @if(isset($actions))
        <div class="card-actions">
            {{ $actions }}
        </div>
        @endif
    </div>
    @endif
    <div class="{{ $padding ? 'card-body' : '' }}">
        {{ $slot }}
    </div>
    @if(isset($footer))
    <div class="card-footer">
        {{ $footer }}
    </div>
    @endif
</div>
