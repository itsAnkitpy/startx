{{--
    The documents already attached to a case, each one openable.

    Written once and included wherever a case is shown, because the case's own page will
    want exactly this list and a second version of it would drift from this one.

    Nothing here decides who may look. The address each link points at asks that again, of
    the person signed in, every time it is opened — so a page that showed this list to the
    wrong person would still not open anything.

    Expects `$case` and `$documents`.
--}}
@if ($documents->isNotEmpty())
    <x-filament::fieldset label="Documents on this case">
        <div style="display: grid; row-gap: 0.5rem;">
            @foreach ($documents as $document)
                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem;">
                    {{-- What it was attached as, not only what the file is called. A scan
                         named after somebody's phone tells whoever is verifying nothing
                         about which question it answers. --}}
                    <span style="font-size: 0.875rem;">
                        {{ $document['step'] }} — {{ $document['label'] }}:
                    </span>

                    <x-filament::link
                        {{-- Relative on purpose. An absolute one is built from whichever
                             host the request came in on, and a link to a case is only ever
                             valid on the company's own subdomain — so there is nothing for
                             it to gain and a wrong host for it to lose. --}}
                        :href="route('case-document', [
                            'case' => $case->getKey(),
                            'sequence' => $document['sequence'],
                            'question' => $document['question'],
                        ], absolute: false)"
                        target="_blank"
                        rel="noopener"
                        icon="heroicon-m-paper-clip"
                        size="sm"
                    >
                        {{ $document['name'] }}
                    </x-filament::link>
                </div>
            @endforeach
        </div>
    </x-filament::fieldset>
@endif
