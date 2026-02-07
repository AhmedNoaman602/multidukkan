@props(['name', 'options', 'placeholder' => 'Select an option', 'label' => null, 'required' => true, 'value' => '', 'valueLabel' => ''])

@php
    $id = $name . '_' . uniqid();
@endphp

<div class="searchable-select-container" style="position: relative;" x-data="{
    open: false,
    search: '',
    selected: '{{ $value }}',
    selectedLabel: '{{ $valueLabel }}',
    options: {{ json_encode($options) }},
    get filteredOptions() {
        if (this.search === '') return this.options;
        return this.options.filter(option => option.name.toLowerCase().includes(this.search.toLowerCase()));
    },
    selectOption(value, label) {
        this.selected = value;
        this.selectedLabel = label;
        this.open = false;
        this.search = '';
    },
    clearSelection() {
        this.selected = '';
        this.selectedLabel = '';
        this.open = false;
    }
}" @click.away="open = false">
    @if($label)
        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">{{ $label }}</label>
    @endif

    <!-- Hidden Input for Form Submission -->
    <input type="hidden" name="{{ $name }}" x-model="selected" :required="{{ $required ? 'true' : 'false' }}">

    <!-- Trigger Button -->
    <div style="position: relative;">
        <div @click="open = !open" 
             style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-input); color: var(--text-primary); cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
            <span x-text="selectedLabel || '{{ $placeholder }}'" :style="selectedLabel ? '' : 'color: var(--text-muted)'"></span>
            <div style="display: flex; align-items: center; gap: 8px;">
                <svg x-show="selectedLabel && !{{ $required ? 'true' : 'false' }}" @click.stop="clearSelection()" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--text-muted); cursor: pointer;" onmouseover="this.style.color='var(--accent-danger)'" onmouseout="this.style.color='var(--text-muted)'">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--text-muted);">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Dropdown Menu -->
    <div x-show="open" style="display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 10; margin-top: 4px; background: #1e1e1e; border: 1px solid var(--border-color); border-radius: var(--radius-sm); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); max-height: 250px; overflow-y: auto;">
        
        <!-- Search Input -->
        <div style="padding: 8px; position: sticky; top: 0; background: #1e1e1e; border-bottom: 1px solid var(--border-color);">
            <input x-model="search" type="text" placeholder="Search..." 
                   style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-input); color: var(--text-primary); outline: none;" >
        </div>

        <!-- Options Request -->
        <template x-for="option in filteredOptions" :key="option.id">
            <div @click="selectOption(option.id, option.name)" 
                 style="padding: 10px 12px; cursor: pointer; border-bottom: 1px solid var(--border-color); transition: background 0.15s ease;"
                 @mouseenter="$el.style.background = 'var(--bg-hover)'"
                 @mouseleave="$el.style.background = 'transparent'">
                <div x-text="option.name" style="font-weight: 500;"></div>
                <div x-text="option.subtext || ''" style="font-size: 12px; color: var(--text-muted);" x-show="option.subtext"></div>
            </div>
        </template>
        
        <div x-show="filteredOptions.length === 0" style="padding: 12px; text-align: center; color: var(--text-muted);">
            No results found.
        </div>
    </div>
</div>
