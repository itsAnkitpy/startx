{{--
    The settlement statement's body, drawn inside the letter page.

    Ours, not a client's wording: every value is escaped here, the reasons a department typed
    among them. The rows, totals and net are the ones the exit's page shows, from the same
    reader, and the two sentences under them say what the page says.
--}}
<p>{{ $tokens['letter.issued_on'] }}</p>

<h3>Settlement statement</h3>

<p>
    {{ $tokens['person.name'] }}<br>
    Employee code: {{ $tokens['person.employee_code'] }}<br>
    Designation: {{ $tokens['person.designation'] }}<br>
    Last working day: {{ $tokens['case.last_working_day'] }}
</p>

<p>Every amount a clearance at {{ $tokens['company.name'] }} claimed from {{ $tokens['person.name'] }} or owes them.</p>

@foreach ([['Claimed from', $claimed], ['Owed to', $owed]] as [$heading, $lines])
    @if ($lines !== [])
        <h4>{{ $heading }} {{ $tokens['person.name'] }}</h4>
        <table>
            <thead>
                <tr><th>Clearance</th><th>For</th><th style="text-align: right;">Amount</th></tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td>{{ $line['clearance'] }}</td>
                        <td>{{ $line['for'] }}@if ($line['disputed']) (disputed)@endif</td>
                        <td style="text-align: right;">{{ $line['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach

@if ($claimed === [] && $owed === [])
    <p>No clearance has claimed or owed any money.</p>
@else
    <table>
        <tr><td>Claimed from {{ $tokens['person.name'] }}</td><td style="text-align: right;">{{ $claimedTotal }}</td></tr>
        <tr><td>Owed to {{ $tokens['person.name'] }}</td><td style="text-align: right;">{{ $owedTotal }}</td></tr>
        <tr><th>Net of claims</th><th style="text-align: right;">{{ $net }}</th></tr>
    </table>
@endif

<p>The net of claims is what the departments raised, not what is paid: payroll applies the wages, the statutory deductions and the limit on what may be deducted.</p>

@if ($gratuityDue !== null)
    <p>Gratuity is due by {{ $gratuityDue }}. Payroll works it out and pays it, so it is not in the net of claims.</p>
@endif
