<table class="data-table">
    <thead>
        <tr>
            <th>{{ __('Entry') }}</th>
            <th>{{ __('Channel') }}</th>
            <th>{{ __('When') }}</th>
            <th>{{ __('Status') }}</th>
        </tr>
    </thead>
    <tbody v-pre>
        @foreach ($sends as $send)
            <tr>
                <td>
                    @if ($send['url'])
                        <a href="{{ $send['url'] }}">{{ $send['title'] }}</a>
                    @else
                        {{ $send['title'] }}
                    @endif
                </td>
                <td>{{ $send['channel'] }}</td>
                <td>{{ ($send[$time] ?? $send['send_at'])->format('M j, Y g:i a') }}</td>
                <td title="{{ $send['message'] }}">{{ ucfirst($send['status']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
