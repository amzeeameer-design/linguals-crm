<?php
namespace App\Console\Commands;
use App\Models\TalentApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class ImportLingualsDatabase extends Command
{
    protected $signature = 'portal:import-database {path? : Private JSON import file}';
    protected $description = 'Import existing vendors, preserving all workbook columns, without inviting accounts or sending emails';
    public function handle(): int
    {
        $path = $this->argument('path') ?: storage_path('app/imports/linguals-database.json');
        if (!is_file($path)) { $this->error('Import file not found.'); return 1; }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (empty($data['records']) || empty($data['schema'])) { $this->error('Invalid import format.'); return 1; }
        $created=0;$updated=0;
        DB::transaction(function () use ($data, &$created, &$updated) {
            foreach ($data['records'] as $row) {
                $a=TalentApplication::where('reference',$row['reference'])->lockForUpdate()->first();
                if (!$a) {
                    $a=TalentApplication::create(['reference'=>$row['reference'],'applicant_type'=>$row['applicant_type'],'name'=>$row['name'],'email'=>$row['email'],'phone'=>$row['phone'],'country'=>$row['country'],'nda_status'=>$row['nda_status'],'profile'=>$row['profile']]);
                    $created++;
                } else {
                    // Re-import refreshes source metadata while keeping reviewed statuses and live profile edits.
                    $profile=$a->profile ?? [];
                    $profile['import_fields']=$row['profile']['import_fields'];$profile['import_original']=$row['profile']['import_original'];$profile['import_source']=$row['profile']['import_source'];
                    foreach ($row['profile'] as $key=>$value) if (!array_key_exists($key,$profile)) $profile[$key]=$value;
                    $a->update(['profile'=>$profile]);$updated++;
                }
                foreach ($row['services'] as $service) $a->services()->firstOrCreate(['service'=>$service['service']],['experience_years'=>$service['experience_years']]);
                foreach ($row['languages'] as $language) {
                    $query=DB::table('application_languages')->where('application_id',$a->id)->where('language',$language['language']);
                    if (!$query->exists()) DB::table('application_languages')->insert(['application_id'=>$a->id,'language'=>$language['language'],'proficiency'=>$language['proficiency']]);
                }
            }
        });
        $this->info("Imported {$created} new vendors; refreshed source metadata for {$updated} existing vendors. No accounts or emails created.");
        return 0;
    }
}
