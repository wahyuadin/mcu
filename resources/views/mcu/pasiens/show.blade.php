@extends('layouts.mcu')
@section('title', $identity?->nama ?? $pasien->nama_karyawan)
@section('content')
    @include('mcu.pasiens.profile')
@endsection
