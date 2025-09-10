<table class="table table-striped table-bordered">
    <thead class="table-dark">
        <tr>
            <th>Voter ID</th>
            <th>Name</th>
            <th>Address</th>
            <th>Phone</th>
            <th>DOB</th>
            <th>Email</th>
            <th>Party</th>
            <th class="notes-column">Notes</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($voters as $voter): ?>
            <tr data-voter-id="<?= htmlspecialchars($voter['VoterID'] ?? '') ?>">
                <td><?= htmlspecialchars($voter['VoterID'] ?? '') ?></td>
                <td><?= htmlspecialchars(($voter['Last_Name'] ?? '') . ', ' . ($voter['First_Name'] ?? '')) ?></td>
                <td><?= nl2br(htmlspecialchars($voter['Voter_Address'] ?? '')) ?></td>
                <td>
                    <?php
                        $phone = preg_replace('/[^0-9]/', '', $voter['Phone_Number'] ?? '');
                        if (strlen($phone) === 10) {
                            echo "(" . substr($phone, 0, 3) . ") " . substr($phone, 3, 3) . "-" . substr($phone, 6);
                        } else {
                            echo htmlspecialchars($voter['Phone_Number'] ?? '');
                        }
                    ?>
                </td>
                <td><?= htmlspecialchars($voter['Birthday'] ? date('m/d', strtotime($voter['Birthday'])) : '') ?></td>
                <td><?= htmlspecialchars($voter['Email_Address'] ?? '') ?></td>
                <td><?= htmlspecialchars($voter['Party'] ?? '') ?></td>
                <td class="notes-column">&nbsp;</td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

