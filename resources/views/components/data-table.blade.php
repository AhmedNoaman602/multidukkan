@props(['headers' => [], 'emptyMessage' => 'No data available'])

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                @foreach($headers as $header)
                <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
