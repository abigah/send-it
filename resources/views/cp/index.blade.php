@extends('statamic::layout')
@section('title', __('Send It'))

@section('content')
    <ui-header title="{{ __('Send It') }}" icon="megaphone"></ui-header>

    <div class="space-y-6">
        <ui-card-panel heading="{{ __('Channels') }}">
            <table class="data-table">
                <tbody v-pre>
                    @foreach ($channels as $channel)
                        <tr>
                            <td>
                                {{ $channel['label'] }}
                                @if ($channel['default'])
                                    <span class="text-xs text-gray-500">({{ __('default') }})</span>
                                @endif
                            </td>
                            <td class="text-end">{{ $channel['configured'] ? __('Ready') : __('Not configured') }}</td>
                        </tr>
                    @endforeach
                    @if ($devices !== null)
                        <tr>
                            <td>{{ __('Registered push devices') }}</td>
                            <td class="text-end">{{ number_format($devices) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </ui-card-panel>

        <ui-card-panel heading="{{ __('Scheduled') }}">
            @if (empty($upcoming))
                <p class="p-4 text-sm text-gray-500">{{ __('Nothing is scheduled. Schedule a send from an entry with the Send It action.') }}</p>
            @else
                @include('send-it::cp.sends', ['sends' => $upcoming, 'time' => 'send_at'])
            @endif
        </ui-card-panel>

        <ui-card-panel heading="{{ __('Recent scheduled sends') }}">
            @if (empty($recent))
                <p class="p-4 text-sm text-gray-500">{{ __('No scheduled sends have gone out yet.') }}</p>
            @else
                @include('send-it::cp.sends', ['sends' => $recent, 'time' => 'processed_at'])
            @endif
        </ui-card-panel>
    </div>
@endsection
