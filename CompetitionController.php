<?php

namespace App\Http\Controllers\Student;

use App\Models\Competition;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Mail\RegisterMail;
use App\Mail\RegisterWithDashboardMail;
use App\Mail\RegisterWithoutDashboardMail;
use App\Models\Student;
use App\Models\Team;
use App\Models\TeamCompetition;
use App\Models\TeamCredential;
use App\Models\TeamMember;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CompetitionController extends Controller
{
    public function show(Competition $competition)
    {
        return view("pages.client.competition")->with([
            "competition" => $competition
        ]);
    }

    public function store(Request $request, Competition $competition)
    {
        $request->validate([
            "nama_tim" => "required",
            "nama" => "required|array|min:2",
            "email" => "required|array|min:2",
            "nim" => "required|array|min:2",
            "no_hp" => "required|array|min:2"
        ]);
        // cek apakah jumlah data di array sesuai ketentuan
        // cek untuk kompetisi pkm-pi
        if ($competition->slug == "pkm-pi") {
            if ($request->nama[0] == null || $request->email[0] == null || $request->nim[0] == null || $request->no_hp[0] == null || $request->nama[1] == null || $request->email[1] == null || $request->nim[1] == null || $request->no_hp[1] == null || $request->nama[2] == null || $request->email[2] == null || $request->nim[2] == null || $request->no_hp[2] == null) {
                return redirect()->route("home.competition", $competition->slug)->with("error", "Data anggota yang dimasukkan tidak sesuai dengan persyaratan. Harap isi data dengan benar!");
            }
        }
        // cek untuk kompetisi selain pkm-pi
        if ($request->nama[0] == null || $request->email[0] == null || $request->nim[0] == null || $request->no_hp[0] == null || $request->nama[1] == null || $request->email[1] == null || $request->nim[1] == null || $request->no_hp[1] == null) {
            return redirect()->route("home.competition", $competition->slug)->with("error", "Data anggota yang dimasukkan tidak sesuai dengan persyaratan. Harap isi data dengan benar!");
        }
        // jika ada data null di array, difilter
        $request->nama = array_filter($request->nama, function ($value) {
            return !is_null($value);
        });
        $request->email = array_filter($request->email, function ($value) {
            return !is_null($value);
        });
        $request->nim = array_filter($request->nim, function ($value) {
            return !is_null($value);
        });
        $request->no_hp = array_filter($request->no_hp, function ($value) {
            return !is_null($value);
        });

        // daftar roles
        DB::beginTransaction();
        try {
            $roles = ["Ketua", "Anggota 1", "Anggota 2"];
            // create team
            $team = Team::firstOrCreate([
                "name" => $request->nama_tim,
                "slug" => Str::slug($request->nama_tim)
            ]);

            // check team credential
            if (!$team->TeamCredential) {
                TeamCredential::create([
                    "team_id" => $team->id,
                    "password" => bcrypt($team->slug . "rahasia")
                ]);
            }

            // pengecekan apakah tim yang terdaftar di lomba tersebut sudah melakukan regis sebelumnya atau belum
            $is_registered = TeamCompetition::whereTeamId($team->id)->whereCompetitionId($competition->id)->exists();
            if ($is_registered) {
                return redirect()->route("home.competition", $competition->slug)->with("error", "Tim sudah pernah mendaftar di lomba ini");
            }

            // create Team Competition
            TeamCompetition::create([
                "team_id" => $team->id,
                "competition_id" => $competition->id
            ]);

            for ($i = 0; $i < count($request->nama); $i++) {
                // create student
                $student = Student::firstOrCreate([
                    "name" => $request->nama[$i],
                    "email" => $request->email[$i],
                    "nim" => $request->nim[$i],
                    "no_hp" => $request->no_hp[$i],
                ]);

                // pada saat tahap role ketua
                if ($roles[$i] === "Ketua") {
                    // pengecekan sudah menjadi ketua di tim lain atau belum. tetapi jika menjadi ketua di tim yang sama, tidak apa-apa
                    $is_ketua = TeamMember::whereStudentId($student->id)->where("team_id", "!=", $team->id)->whereRole("Ketua")->get();
                    if (count($is_ketua) > 0) {
                        return redirect()->route("home.competition", $competition->slug)->with("error", $student->name . " sudah menjabat sebagai ketua di tim lain. Harap memilih ketua yang lain");
                    }
                }
                // di luar tahap role ketua
                // create Team Member
                TeamMember::firstOrCreate([
                    "student_id" => $student->id,
                    "team_id" => $team->id,
                    "role" => $roles[$i]
                ]);
                try {
                    if ($competition->title === "CPC" || $competition->title === "CTF") {
                        Mail::to($student->email)->queue(new RegisterWithoutDashboardMail($student, $team, $competition));
                    } else {
                        Mail::to($student->email)->queue(new RegisterWithDashboardMail($student, $team, $competition));
                    }
                    //Mail::to($student->email)->queue(new RegisterMail($student, $team, $competition));
                } catch (Exception $e) {
                    DB::rollBack();
                    return redirect()->route("home.competition", $competition->slug)->with("error", $e->getMessage());
                }
            }

            DB::commit();
            return redirect()->route("home.competition", $competition->slug)->with("success", "Registrasi tim sukses, Silahkan cek email untuk informasi pengumpulan");
        } catch (Exception $e) {
            DB::rollBack();
            return redirect()->route("home.competition", $competition->slug)->with("error", "Registrasi gagal karena mencoba untuk mendaftar dengan nama tim yang sama tetapi dengan struktur anggota yang berbeda.");
        }
    }
}
